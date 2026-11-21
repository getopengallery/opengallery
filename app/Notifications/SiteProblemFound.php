<?php

namespace App\Notifications;

use App\Filament\Widgets\SiteHealth as SiteHealthWidget;
use App\Support\Dashboard\SiteHealth;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells people who change site settings that the site health check found a
 * new problem (App\Support\SiteHealthNotices). In the gallery always; by
 * e-mail too for problems that stop the site from working ("danger").
 * Marked as read for everyone once the problem is gone.
 */
class SiteProblemFound extends InAppNotification
{
    /** @param  array{key: string, severity: string, count: ?int}  $problem */
    public function __construct(public array $problem) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels(mail: $this->problem['severity'] === SiteHealth::DANGER);
    }

    public function inAppData(): array
    {
        return $this->problem;
    }

    public static function message(array $data): InAppMessage
    {
        $severity = (string) ($data['severity'] ?? SiteHealth::WARNING);
        $info = SiteHealthWidget::describe([
            'key' => (string) ($data['key'] ?? ''),
            'severity' => $severity,
            'count' => isset($data['count']) ? (int) $data['count'] : null,
        ]);

        return new InAppMessage(
            title: $info['title'],
            body: $info['text'],
            url: $info['url'],
            icon: $severity === SiteHealth::DANGER ? Heroicon::OutlinedExclamationCircle : Heroicon::OutlinedExclamationTriangle,
            color: $severity === SiteHealth::DANGER ? 'danger' : 'warning',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $info = SiteHealthWidget::describe($this->problem);

        return (new MailMessage)
            ->subject(__(':site: :problem', ['site' => config('app.name'), 'problem' => $info['title']]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('The site health check of :site found a problem:', ['site' => config('app.name')]))
            ->line($info['title'].': '.$info['text'])
            ->action($info['link'], $info['url'])
            ->line(__('You get this e-mail because you can change the site settings. It is sent once per problem.'));
    }
}
