<?php

namespace App\Support;

use App\Models\TestMail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Mail transport "mailbox" (registered in AppServiceProvider): keeps every
 * message in the test_mails table instead of sending it. Works on any host,
 * no mail server needed.
 */
class MailboxTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $addresses = fn (array $list) => implode(', ', array_map(fn (Address $address) => $address->toString(), $list));

        TestMail::create([
            'from' => $addresses($email->getFrom()) ?: null,
            'to' => $addresses([...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]),
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
            'created_at' => now(),
        ]);

        TestMail::trim();
    }

    public function __toString(): string
    {
        return 'mailbox://';
    }
}
