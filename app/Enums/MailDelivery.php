<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How the gallery sends e-mail (Admin → Settings). Every case except
 * Server is also the name of the Laravel mailer it selects.
 */
enum MailDelivery: string implements HasDescription, HasLabel
{
    case Server = 'server';
    case Smtp = 'smtp';
    case Sendmail = 'sendmail';
    /** Kept in the gallery's test mailbox (App\Support\MailboxTransport); nobody receives anything. */
    case Mailbox = 'mailbox';

    public function getLabel(): string
    {
        return match ($this) {
            self::Server => __('Server configuration'),
            self::Smtp => __('SMTP server'),
            self::Sendmail => __('This web server (sendmail)'),
            self::Mailbox => __('Test mailbox (nothing is sent)'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Server => __('Uses the mail settings from the .env file.'),
            self::Smtp => __('The mail server of your host or e-mail provider. Most reliable.'),
            self::Sendmail => __('The mail program installed on the server. Works on many shared hosts, but messages often end up in spam.'),
            self::Mailbox => __('For trying things out without a mail server: e-mails are kept in the gallery, where super administrators can read them under "Test mailbox". Nobody receives them.'),
        };
    }

    /** The Laravel mailer to use, or null to keep the configured default. */
    public function mailer(): ?string
    {
        return $this === self::Server ? null : $this->value;
    }
}
