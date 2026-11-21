<?php

namespace App\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;

/**
 * What one notification says in the gallery, already in the reader's
 * language: shown in the admin's notification panel (toFilament()) and on
 * the site's notifications page. Made from stored values each time it is
 * shown (InAppNotification::message()).
 */
final class InAppMessage
{
    /**
     * @param  string  $color  Filament colour of the icon: primary, success, warning, danger, info or gray
     */
    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $url = null,
        public Heroicon $icon = Heroicon::OutlinedBell,
        public string $color = 'primary',
    ) {}

    public function toFilament(): FilamentNotification
    {
        return FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->icon($this->icon)
            ->iconColor($this->color)
            ->actions($this->url === null ? [] : [
                Action::make('open')
                    ->label(__('Open'))
                    ->url($this->url)
                    ->markAsRead(),
            ]);
    }
}
