<?php

namespace App\Services;

use Closure;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;

class PhpMailTransport extends AbstractTransport
{
    public function __construct(private ?Closure $sender = null)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->sender === null && ! function_exists('mail')) {
            throw new TransportException('PHP mail() is disabled on this server. Ask your hosting provider to enable it or select SMTP.');
        }
        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException('PHP mail requires a structured MIME message.');
        }
        [$headers, $body] = explode("\r\n\r\n", $message->toString(), 2);
        $headers = preg_replace('/^(?:To|Cc|Bcc|Subject):[^\r\n]*(?:\r\n[ \t][^\r\n]*)*\r\n/mi', '', $headers."\r\n");
        $subject = $original->getPreparedHeaders()->get('Subject')?->getBodyAsString() ?? '';
        $subject = str_replace(["\r", "\n"], '', $subject);
        foreach ($message->getEnvelope()->getRecipients() as $recipient) {
            $accepted = $this->sender !== null
                ? ($this->sender)($recipient->getAddress(), $subject, $body, $headers)
                : @\mail($recipient->getAddress(), $subject, $body, $headers);
            if (! $accepted) {
                throw new TransportException('PHP mail() could not hand the message to the hosting mail server. Check cPanel Track Delivery and ask your host to check PHP mail and Exim.');
            }
        }
    }

    public function __toString(): string
    {
        return 'php-mail';
    }
}
