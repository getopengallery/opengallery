<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsGroup;
use App\Filament\Clusters\Settings\SettingsPage;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Settings → Access: who can create an account and what visitors see. */
class AccessSettings extends SettingsPage
{
    protected const FIELDS = [
        'registration' => [SiteSettings::REGISTRATION, 'Registration'],
        'require_approval' => [SiteSettings::REQUIRE_APPROVAL, 'New accounts need approval'],
        'notify_registrations' => [SiteSettings::NOTIFY_REGISTRATIONS, 'Notify about new accounts'],
        'public_albums' => [SiteSettings::PUBLIC_ALBUMS, 'Public albums'],
        'guest_home' => [SiteSettings::GUEST_HOME, 'Guest start page'],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = SettingsGroup::People;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'access';

    public static function getNavigationLabel(): string
    {
        return __('Access');
    }

    protected function formValues(): array
    {
        return [
            'registration' => SiteSettings::registrationEnabled(),
            'require_approval' => SiteSettings::requiresApproval(),
            'notify_registrations' => SiteSettings::notifiesAboutRegistrations(),
            'public_albums' => SiteSettings::publicAlbumsEnabled(),
            'guest_home' => SiteSettings::guestHome(),
        ];
    }

    protected function formSections(): array
    {
        return [
            Section::make(__('Accounts'))
                ->columns(1)
                ->schema([
                    Toggle::make('registration')
                        ->label(__('Allow people to create accounts'))
                        ->helperText(__('When off, only administrators can add users. Applies to e-mail and OAuth sign-up.'))
                        ->live(),
                    Toggle::make('require_approval')
                        ->label(__('New accounts need an administrator\'s approval'))
                        ->helperText(__('People who sign up can log in, but only see a "waiting for approval" page until someone who manages users approves them under Users. Those people are told about every new account.'))
                        ->visible(fn (Get $get) => (bool) $get('registration')),
                    Toggle::make('notify_registrations')
                        ->label(__('Tell people who manage users when someone creates an account'))
                        ->helperText(__('They get a notification in the admin and, while e-mail works, an e-mail, so new members do not wait unnoticed.'))
                        ->visible(fn (Get $get) => (bool) $get('registration')),
                ]),

            Section::make(__('Visitors'))
                ->columns(1)
                ->schema([
                    Toggle::make('public_albums')
                        ->label(__('Show public albums to visitors who are not logged in'))
                        ->helperText(__('When off, albums marked "Public" are only visible after logging in. Share links for unlisted albums keep working.'))
                        ->live(),
                    Radio::make('guest_home')
                        ->label(__('What visitors who are not logged in see'))
                        ->options([
                            'landing' => __('A landing page (its text, image and layout are set in the theme: Themes → ⚙)'),
                            'login' => __('The login page'),
                        ])
                        ->required()
                        ->live()
                        ->visible(fn (Get $get) => ! $get('public_albums')),
                ]),
        ];
    }
}
