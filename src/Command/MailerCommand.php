<?php

namespace Web\Command;

class MailerCommand extends \CLIFramework\Command
{
    public function execute(array $emails, $data)
    {
        foreach ($emails as $email) {
            $message = new \Zend\Mail\Message;
            $message->addTo($email);
            $message->addFrom($data['sender']);
            $message->setSubject($data['subject']);
            $htmlPart = new \Zend\Mime\Part($data['html']);
            $htmlPart->type = 'text/html';
            $htmlPart->charset = 'UTF-8';
            $body = new \Zend\Mime\Message;
            $body->addPart($htmlPart);
            $message->setBody($body);
            $transport = new \Zend\Mail\Transport\Sendmail;
            $transport->send($message);
        }
    }
}
