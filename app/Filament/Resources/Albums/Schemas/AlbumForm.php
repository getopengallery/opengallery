<?php

namespace App\Filament\Resources\Albums\Schemas;

use App\Enums\CollaboratorRole;
use App\Enums\Permission;
use App\Enums\PhotoOrder;
use App\Enums\Visibility;
use App\Filament\Forms\Components\MediaPickerField;
use App\Filament\Forms\Components\PeopleAndGroupsSelect;
use App\Filament\Forms\Components\TagPicker;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Models\Album;
use App\Support\AlbumCollaboration;
use App\Support\AlbumSettings;
use App\Support\Dashboard\DashboardLayout;
use App\Support\Mailing;
use App\Support\SiteSettings;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Album create / edit form: the same cards in the same places on both pages,
 * on the dashboard's grid (DashboardLayout): a wide one with a normal one
 * beside it, then the full-width Photos section (on the edit page that is
 * PhotosRelationManager). Collaborators see what their role allows: Editors
 * everything but owner and parent, everyone else the album read-only.
 */
class AlbumForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(DashboardLayout::GRID_COLUMNS)
            ->components([
                self::albumSection()->columnSpan(fn (mixed $livewire) => self::isSubAlbumThroughRole($livewire) ? 'full' : DashboardLayout::WIDE_SPAN),
                self::coverSection()->columnSpan(DashboardLayout::BESIDE_WIDE_SPAN),
                self::sharingSection()->columnSpan(fn (?Album $record) => self::hasCollaboratorsCard($record) ? DashboardLayout::WIDE_SPAN : 'full'),
                self::collaboratorsSection()->columnSpan(fn (?Album $record) => self::editsSettings($record) ? DashboardLayout::BESIDE_WIDE_SPAN : 'full'),
                self::inviteSection()->columnSpan(DashboardLayout::BESIDE_WIDE_SPAN),
                self::createPhotosSection()->columnSpanFull(),
            ]);
    }

    /** The role choice with what each role may do, including that Editors can publish and reach originals. */
    public static function rolePicker(string $name = 'role'): Radio
    {
        return Radio::make($name)
            ->label(__('Role'))
            ->options(CollaboratorRole::class)
            ->descriptions(collect(CollaboratorRole::cases())->mapWithKeys(fn (CollaboratorRole $role) => [$role->value => $role->getDescription()])->all())
            ->default(CollaboratorRole::Contributor->value)
            ->required();
    }

    /** New albums, and everyone who may change this one's settings. */
    private static function editsSettings(?Album $record): bool
    {
        return $record === null || auth()->user()->can('update', $record) || auth()->user()->can('editSettings', $record);
    }

    /** "Add album inside" on an album the person works on through a role. */
    private static function isSubAlbumThroughRole(mixed $livewire): bool
    {
        return $livewire instanceof CreateAlbum && $livewire->createsInsideSharedAlbum();
    }

    private static function mayInvite(): bool
    {
        return auth()->user()->can(Permission::InviteCollaborators->value) || auth()->user()->can(Permission::EditAnyAlbum->value);
    }

    /** Whether the normal card next to Visibility & sharing is there. */
    private static function hasCollaboratorsCard(?Album $record): bool
    {
        return $record === null ? self::mayInvite() : ! $record->isSystem();
    }

    private static function albumSection(): Section
    {
        return Section::make(__('Album'))->extraAttributes(['class' => 'og-album-card'])
            ->columns(2)
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
                TagPicker::make('tag_names')
                    ->columnSpanFull(),
                Toggle::make('tags_apply_to_sub_albums')
                    ->label(__('Also apply these tags to sub-albums'))
                    ->helperText(__('Albums inside this one, at every level, and their photos get these tags too.'))
                    ->visible(fn (): bool => SiteSettings::taggingEnabled())
                    ->columnSpanFull(),
                Select::make('parent_id')
                    ->visible(fn (?Album $record, mixed $livewire): bool => ! self::isSubAlbumThroughRole($livewire)
                        && ($record === null || auth()->user()->can('update', $record)))
                    ->label(__('Inside album'))
                    ->placeholder(__('None (top level)'))
                    // Only albums the person may edit (and the current parent), so nobody
                    // sees other people's album titles or nests into their albums.
                    ->relationship('parent', 'title', fn (Builder $query, ?Album $record) => $query
                        ->when($record, fn (Builder $q) => $q->whereKeyNot($record->selfAndDescendantIds()))
                        ->unless(auth()->user()->can(Permission::EditAnyAlbum->value), fn (Builder $q) => $q->where(fn (Builder $mine) => $mine
                            ->where('owner_id', auth()->id())
                            ->when($record?->parent_id, fn (Builder $current, int $parentId) => $current->orWhereKey($parentId))))
                        ->orderBy('tree_path'))
                    ->getOptionLabelFromRecordUsing(fn (Album $album) => $album->path())
                    ->default(fn () => request()->integer('parent') ?: null)
                    ->searchable()
                    ->preload(),
                // Only people who may edit every album give albums to someone else; everyone
                // else creates albums as themselves (CreateAlbum) and never sees the user list.
                Select::make('owner_id')
                    ->label(__('Owner'))
                    ->relationship('owner', 'name')
                    ->default(fn () => auth()->id())
                    ->searchable()
                    ->required()
                    ->visible(fn (mixed $livewire): bool => ! self::isSubAlbumThroughRole($livewire) && auth()->user()->can(Permission::EditAnyAlbum->value)),
                Select::make('photo_order')
                    ->label(__('Sort photos by'))
                    ->options(PhotoOrder::class)
                    ->default(fn () => SiteSettings::defaultPhotoOrder())
                    ->selectablePlaceholder(false)
                    ->helperText(fn (?Album $record) => self::editsSettings($record)
                        ? __('Used on the site and in the Photos section below. With "Manual" you can drag photos into order.')
                        : null)
                    // Next to "Inside album", unless the owner field takes that place or there is no such field.
                    ->columnSpan(fn (?Album $record, mixed $livewire): int|string => auth()->user()->can(Permission::EditAnyAlbum->value)
                        || self::isSubAlbumThroughRole($livewire)
                        || ($record && ! auth()->user()->can('update', $record)) ? 'full' : 1),
            ]);
    }

    private static function coverSection(): Section
    {
        return Section::make(__('Cover'))->extraAttributes(['class' => 'og-album-card'])
            ->visible(fn (mixed $livewire): bool => ! self::isSubAlbumThroughRole($livewire))
            ->schema([
                MediaPickerField::make('cover_photo_id')
                    ->label(__('Cover'))
                    ->hiddenLabel()
                    ->chooseLabel(__('Choose cover'))
                    ->confirmLabel(__('Use as cover'))
                    ->pickerHeading(__('Choose a cover'))
                    ->emptyText(fn (?Album $record) => $record ? __('No cover yet.') : __('No cover chosen. The first photo is used.'))
                    ->scopeAlbum(fn (?Album $record) => $record?->id, 'only')
                    ->limitToAlbum(fn (?Album $record) => AlbumCollaboration::pickerAlbumId($record, auth()->user()))
                    ->uploadTo(fn (?Album $record) => AlbumCollaboration::pickerAlbumId($record, auth()->user()) ? 'album:'.$record->id : 'inbox')
                    ->helperText(fn (?Album $record) => match (true) {
                        ! self::editsSettings($record) => null,
                        AlbumCollaboration::pickerAlbumId($record, auth()->user()) !== null => __('Choose a photo from this album or one of your own uploads. Your own upload joins the album.'),
                        default => __('A photo from elsewhere in the library is added to this album too, so visitors can see the cover.'),
                    })
                    ->disabled(fn (?Album $record) => ! self::editsSettings($record)),
            ]);
    }

    private static function sharingSection(): Section
    {
        return Section::make(__('Visibility & sharing'))->extraAttributes(['class' => 'og-album-card'])
            ->visible(fn (?Album $record, mixed $livewire): bool => ! self::isSubAlbumThroughRole($livewire) && self::editsSettings($record))
            ->columns(2)
            ->schema([
                Select::make('visibility')
                    ->options(Visibility::class)
                    ->default(Visibility::Private)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
                TextInput::make('share_token')
                    ->label(__('Share link'))
                    ->formatStateUsing(fn ($state) => $state ? route('albums.shared', $state) : __('Created when you save'))
                    ->copyable(fn ($state) => filled($state))
                    ->readOnly()
                    ->dehydrated(false)
                    ->visible(fn (Get $get) => self::is($get('visibility'), Visibility::Unlisted)),
                // One picker for both. People are found by search, never preloaded: a gallery can have many members.
                PeopleAndGroupsSelect::make('shared_with')
                    ->label(__('Shared with'))
                    ->afterStateHydrated(fn (PeopleAndGroupsSelect $component, ?Album $record) => $component->state($record ? AlbumSettings::shareKeys($record) : []))
                    ->dehydrated(false)
                    // Editors without the invite permission can only take people and groups off the list.
                    ->onlyKeys(fn (?Album $record): ?array => $record && ! auth()->user()->can('update', $record) && ! auth()->user()->can('searchDirectory', $record)
                        ? AlbumSettings::shareKeys($record)
                        : null)
                    // People without an account are added by typing their e-mail address (AlbumGuests).
                    ->allowEmails(fn (): bool => SiteSettings::albumGuestsEnabled() && Mailing::delivers())
                    ->searchPrompt(fn (): string => SiteSettings::albumGuestsEnabled() && Mailing::delivers()
                        ? __('Type a name or e-mail address. People without an account can be invited by their e-mail address.')
                        : __('Type a name or e-mail address'))
                    ->helperText(fn (): ?string => SiteSettings::albumGuestsEnabled() && Mailing::delivers()
                        ? (SiteSettings::albumGuestDays() === 0
                            ? __('People added by e-mail (✉) get a personal link and confirm it with a code sent to them.')
                            : trans_choice('People added by e-mail (✉) get a personal link and confirm it with a code sent to them. Their access ends after :count day.|People added by e-mail (✉) get a personal link and confirm it with a code sent to them. Their access ends after :count days.', SiteSettings::albumGuestDays()))
                        : null)
                    ->saveRelationshipsUsing(fn (Album $record, ?array $state) => AlbumSettings::syncShareKeys($record, $state ?? [], auth()->user()))
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => self::is($get('visibility'), Visibility::Restricted)),
                // Read by CreateAlbum / EditAlbum (ShareNotices); not stored.
                Toggle::make('notify_shared')
                    ->label(__('Tell people when they get access'))
                    ->default(true)
                    ->dehydrated(false)
                    ->helperText(fn (): string => Mailing::delivers()
                        ? __('They get a notification in the gallery and an e-mail. Only people who are added get one, also through a group. You never get one yourself.')
                        : __('They get a notification in the gallery. E-mail is not set up in the site settings, so no e-mail is sent.'))
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => self::is($get('visibility'), Visibility::Restricted)),

                Toggle::make('protect')
                    ->label(__('Require a password'))
                    ->columnSpanFull()
                    ->helperText(__('Visitors must enter it before they see the photos. You, staff, collaborators and people the album is shared with directly never need it.'))
                    ->afterStateHydrated(fn (Toggle $component, ?Album $record) => $component->state((bool) $record?->hasPassword()))
                    ->live()
                    ->visible(fn (Get $get) => ! self::is($get('visibility'), Visibility::Private)),
                TextInput::make('password')
                    ->label(fn (?Album $record) => $record?->hasPassword() ? __('New password') : __('Password'))
                    ->password()
                    ->revealable()
                    ->minLength(4)
                    ->maxLength(100)
                    ->autocomplete('new-password')
                    ->placeholder(fn (?Album $record) => $record?->hasPassword() ? __('Leave empty to keep it') : null)
                    ->helperText(fn (?Album $record) => $record?->hasPassword() ? __('Changing it locks out everyone who used the old password.') : null)
                    ->required(fn (Get $get, ?Album $record) => $get('protect') && ! $record?->hasPassword())
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->visible(fn (Get $get) => $get('protect') && ! self::is($get('visibility'), Visibility::Private)),

                DateTimePicker::make('access_expires_at')
                    ->label(__('Share until'))
                    ->native(false)
                    ->seconds(false)
                    ->minDate(fn (?Album $record) => $record?->access_expires_at?->isPast() ? null : now())
                    ->helperText(__('Optional. Afterwards the share link, the password and public access stop working; only you, staff, collaborators and people it is shared with directly can open the album.'))
                    ->visible(fn (Get $get) => ! self::is($get('visibility'), Visibility::Private)),

                Toggle::make('allow_downloads')
                    ->label(__('Visitors may download the photos'))
                    ->columnSpanFull()
                    ->helperText(__('Adds a download button for the original file to the photo viewer on the site. Otherwise visitors only get the photos in screen size.'))
                    ->visible(fn (Get $get) => SiteSettings::downloadsEnabled() && ! self::is($get('visibility'), Visibility::Private)),
            ]);
    }

    /** Edit page only: who works on the album. The card's content and actions are on the page (ManagesCollaborators). */
    private static function collaboratorsSection(): Section
    {
        return Section::make(__('Collaborators'))->extraAttributes(['class' => 'og-album-card'])
            ->description(__('People and groups who work on this album with you.'))
            ->visible(fn (?Album $record): bool => $record !== null && ! $record->isSystem() && (auth()->user()?->can('viewCollaborators', $record) ?? false))
            ->schema([View::make('filament.albums.collaborators')]);
    }

    /** Create page only: the same card, with people and groups to invite when the album is created. */
    private static function inviteSection(): Section
    {
        return Section::make(__('Collaborators'))->extraAttributes(['class' => 'og-album-card'])
            ->description(__('People and groups who work on this album with you.'))
            ->visible(fn (?Album $record, mixed $livewire): bool => $record === null && ! self::isSubAlbumThroughRole($livewire) && self::mayInvite())
            ->schema([
                PeopleAndGroupsSelect::make('invitees')
                    ->label(__('People and groups'))
                    ->dehydrated(false),
                // A compact list here; the Add dialog on the edit page explains each role in full.
                Select::make('invitee_role')
                    ->label(__('Role'))
                    ->options(CollaboratorRole::class)
                    ->default(CollaboratorRole::Contributor->value)
                    ->selectablePlaceholder(false)
                    ->helperText(__('Editors can publish the album and, by turning on downloads, reach the original files.'))
                    ->dehydrated(false),
            ]);
    }

    /** Create page only: the album's first photos, from the library or uploaded. */
    private static function createPhotosSection(): Section
    {
        return Section::make(__('Photos'))
            ->extraAttributes(['class' => 'og-album-card'])
            ->description(__('Choose photos from the library or upload new ones. You can add more later.'))
            ->visible(fn (?Album $record, mixed $livewire): bool => $record === null && ! self::isSubAlbumThroughRole($livewire))
            ->schema([
                MediaPickerField::make('photo_ids')
                    ->label(__('Photos'))
                    ->hiddenLabel()
                    ->multiple()
                    ->chooseLabel(__('Add photos'))
                    ->confirmLabel(__('Add to album'))
                    ->pickerHeading(__('Add photos from the library'))
                    ->uploadTo('inbox'),
            ]);
    }

    private static function is(mixed $state, Visibility $expected): bool
    {
        return ($state instanceof Visibility ? $state : Visibility::tryFrom((string) $state)) === $expected;
    }
}
