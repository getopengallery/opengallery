<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\ImagePreview;
use App\Filament\Forms\Components\ImageUpload;
use App\Support\Avatars;
use App\Support\Invitations;
use App\Support\Locales;
use App\Support\Mailing;
use App\Support\SiteSettings;
use App\Support\StorageQuota;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // One column: account first, then access.
            ->columns(1)
            ->components([
                Section::make(__('Account'))
                    ->schema([
                        Flex::make([
                            self::avatarField(),
                            Grid::make(2)->schema([
                                TextInput::make('name')
                                    ->label(__('Name'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('email')
                                    ->label(__('E-mail address'))
                                    ->email()
                                    ->unique(ignoreRecord: true)
                                    ->required(),
                                // New users: an invitation lets them choose their own password
                                // (CreateUser); a password is only needed without one.
                                Toggle::make('send_invitation')
                                    ->label(__('Send an invitation'))
                                    ->default(fn (): bool => Mailing::delivers())
                                    ->dehydrated(false)
                                    ->live()
                                    ->helperText(fn (): string => Mailing::delivers()
                                        ? trans_choice('E-mails a link to choose a password, valid for :count day.|E-mails a link to choose a password, valid for :count days.', Invitations::validForDays())
                                        : __('E-mail is not set up, so you get the link to pass on yourself.'))
                                    ->columnSpanFull()
                                    ->visibleOn('create'),
                                TextInput::make('password')
                                    ->label(__('Password'))
                                    ->password()
                                    ->revealable()
                                    ->rule(Password::defaults())
                                    ->required(fn (string $operation, Get $get) => $operation === 'create' && ! $get('send_invitation'))
                                    ->visible(fn (string $operation, Get $get) => $operation === 'edit' || ! $get('send_invitation'))
                                    ->dehydrated(fn (?string $state) => filled($state))
                                    ->helperText(fn (string $operation) => $operation === 'edit' ? __('Leave empty to keep the current password.') : null),
                                // People added by an administrator count as confirmed.
                                DateTimePicker::make('email_verified_at')
                                    ->label(__('E-mail verified at'))
                                    ->default(now()),
                                Select::make('locale')
                                    ->label(__('Language'))
                                    ->options(Locales::available())
                                    ->placeholder(fn (): string => __('Site default (:language)', ['language' => Locales::name(Locales::default())]))
                                    ->in(array_keys(Locales::available()))
                                    ->helperText(__('For the site, the admin and e-mails. People can change it in their profile.')),
                            ]),
                        ])->from('md'),
                    ]),

                Section::make(__('Access'))
                    ->columns(2)
                    ->schema([
                        // Only roles the editor may give (Role::assignableBy()); Filament rejects
                        // other ids on save and never detaches roles outside this query.
                        Select::make('roles')
                            ->relationship(
                                'roles',
                                'name',
                                modifyQueryUsing: fn (Builder $query) => $query->assignableBy(auth()->user()),
                            )
                            ->multiple()
                            ->preload()
                            ->helperText(__('Roles grant site-wide capabilities. You can only give roles whose permissions you have yourself.')),
                        Select::make('groups')
                            ->relationship('groups', 'name')
                            ->multiple()
                            ->preload()
                            ->helperText(__('Groups decide which restricted albums the user can see.')),
                        // Read by EditUser (GroupNotices); not stored. Off unless turned on, like the album form's switch.
                        Toggle::make('notify_group_access')
                            ->label(__('Tell people when they get access'))
                            ->helperText(__('They get one notification about the albums new groups give them, and an e-mail while e-mail works.'))
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                    ]),

                // Read by CreateUser/EditUser (the storage columns are not mass assignable).
                Section::make(__('Storage'))
                    ->visible(fn (): bool => SiteSettings::storageLimitsEnabled())
                    ->schema([
                        Toggle::make('storage_unlimited')
                            ->label(__('Unlimited storage'))
                            ->helperText(__('This user can upload without a limit.'))
                            ->dehydrated(false)
                            ->live(),
                        TextInput::make('storage_quota_gb')
                            ->label(__('Storage limit'))
                            ->integer()
                            ->minValue(SiteSettings::STORAGE_QUOTA_MIN_GB)
                            ->maxValue(SiteSettings::STORAGE_QUOTA_MAX_GB)
                            ->suffix('GB')
                            ->placeholder(fn (): string => __('Default (:size GB)', ['size' => SiteSettings::defaultStorageQuotaGb()]))
                            ->maxWidth('xs')
                            ->dehydrated(false)
                            ->live()
                            ->visible(fn (Get $get): bool => ! $get('storage_unlimited'))
                            ->helperText(__('How much space this user\'s photos may take, thumbnails included. Leave empty to use the default limit from the storage settings.'))
                            ->hintAction(
                                Action::make('resetStorageQuota')
                                    ->label(__('Reset to default'))
                                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                                    ->visible(fn (Get $get): bool => filled($get('storage_quota_gb')))
                                    ->action(fn (Set $set) => $set('storage_quota_gb', null)),
                            ),
                    ]),
            ]);
    }

    /**
     * The storage columns as the form sets them; nothing while storage
     * limits are off (the section is hidden then).
     *
     * @param  array<string, mixed>  $data  the form's raw state
     * @return array{storage_unlimited?: bool, storage_quota_bytes?: ?int}
     */
    public static function storageAttributes(array $data): array
    {
        if (! SiteSettings::storageLimitsEnabled()) {
            return [];
        }

        $unlimited = (bool) ($data['storage_unlimited'] ?? false);
        $gb = (int) ($data['storage_quota_gb'] ?? 0);

        return [
            'storage_unlimited' => $unlimited,
            'storage_quota_bytes' => ! $unlimited && $gb > 0 ? StorageQuota::gbToBytes($gb) : null,
        ];
    }

    /** Profile picture: re-encoded on the server (Avatars::store); replaced files are deleted by the User model. */
    private static function avatarField(): ImageUpload
    {
        return ImageUpload::make('avatar_path')
            ->label(__('Picture'))
            // Any shape is fine: the server crops it to a square.
            ->previewAs(ImagePreview::Square)
            ->disk(Avatars::DISK)
            ->directory(Avatars::DIRECTORY)
            ->visibility('private')
            ->acceptedFileTypes(Avatars::MIME_TYPES)
            ->maxSize(Avatars::MAX_KB)
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => Avatars::store($file->getRealPath()))
            ->grow(false);
    }
}
