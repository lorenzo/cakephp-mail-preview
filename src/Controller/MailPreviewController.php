<?php
namespace Josegonzalez\MailPreview\Controller;

use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Network\Exception\ForbiddenException;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class MailPreviewController extends AppController
{
    /**
     * Before filter callback.
     *
     * @param \Cake\Event\Event $event The beforeRender event.
     * @return void
     * @throws Cake\Network\Exception\ForbiddenException
     */
    public function beforeFilter(Event $event)
    {
        if (Configure::read('debug') === 0) {
            throw new ForbiddenException;
        }
    }

    /**
     * Handles mail-preview/index
     *
     * @return void
     */
    public function index()
    {
        $this->viewBuilder()->layout(false);
        $this->set('mailPreviews', $this->getMailPreviews());
    }

    /**
     * Handles mail-preview/email
     *
     * @return void
     */
    public function email()
    {
        $name = implode('::', $this->request->params['pass']);
        list($mailPreview, $emailName) = $this->findPreview($this->request->params['pass']);
        $partType = $this->request->query('part', null);

        $email = $mailPreview->$emailName();
        $this->viewBuilder()->layout(false);

        if ($partType) {
            if ($part = $this->findPart($email, $partType)) {
                Configure::write('debug', 0);
                $this->response->type($partType);
                $this->response->body($part);

                return $this->response->send();
            }

            throw new Exception(sprintf(
                "Email part '%s' not found in %s::%s",
                $partType,
                $mailPreview->name(),
                $emailName
            ));
        }

        $this->set('title', sprintf('Mailer Preview for %s', $name));
        $this->set('email', $email);
        $this->set('part', $this->findPreferredPart($email, $this->request->query('part')));
    }

    /**
     * Retrieves an array of MailPreview objects
     *
     * @return array
     **/
    protected function getMailPreviews()
    {
        $classNames = Configure::read('MailPreview.Previews.classNames');
        if (empty($classNames)) {
            $path = APP . 'Mailer' . DS . 'Preview' . DS;
            $classNames = $this->getMailPreviewsFromPath($path);
        }

        $mailPreviews = [];
        foreach ($classNames as $className) {
            $mailPreviews[] = new $className;
        }

        return $mailPreviews;
    }

    /**
     * Returns an array of MailPreview class names for a given path
     *
     * @param string $path Path to MailPreview directory
     * @return array
     **/
    protected function getMailPreviewsFromPath($path)
    {
        if (!is_dir($path)) {
            return [];
        }

        $fqcns = [];
        $allFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        $phpFiles = new RegexIterator($allFiles, '/\.php$/');
        foreach ($phpFiles as $phpFile) {
            $content = file_get_contents($phpFile->getRealPath());
            $tokens = token_get_all($content);
            $namespace = '';
            for ($index = 0; isset($tokens[$index]); $index++) {
                if (!isset($tokens[$index][0])) {
                    continue;
                }
                if (T_NAMESPACE === $tokens[$index][0]) {
                    $index += 2; // Skip namespace keyword and whitespace
                    while (isset($tokens[$index]) && is_array($tokens[$index])) {
                        $namespace .= $tokens[$index++][1];
                    }
                }
                if (T_CLASS === $tokens[$index][0]) {
                    $index += 2; // Skip class keyword and whitespace
                    $fqcns[] = $namespace . '\\' . $tokens[$index][1];
                }
            }
        }

        return $fqcns;
    }

    /**
     * Finds a specified email part
     *
     * @param array $email An array of email data
     * @param string $partType The name of a part
     * @return null|string
     **/
    protected function findPart(array $email, $partType)
    {
        foreach ($email['parts'] as $part => $content) {
            if ($part === $partType) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Finds a specified email part or the first part available
     *
     * @param array $email An array of email data
     * @param string $partType The name of a part
     * @return null|string
     **/
    protected function findPreferredPart(array $email, $partType)
    {
        if (empty($partType)) {
            foreach ($email['parts'] as $part => $content) {
                return $part;
            }
        }

        $part = $this->findPart($email, $partType);

        return $part ?: null;
    }

    /**
     * Returns a matching MailPreview object with name
     *
     * @param string $path A MailPreview/email path
     * @return array
     * @throws Exception
     **/
    protected function findPreview($path)
    {
        list($previewName, $emailName) = $path;
        foreach ($this->getMailPreviews() as $mailPreview) {
            if ($mailPreview->name() !== $previewName) {
                continue;
            }

            $email = $mailPreview->find($emailName);
            if (!$email) {
                continue;
            }

            return [$mailPreview, $email];
        }

        throw new Exception("Mailer preview ${name} not found");
    }
}
