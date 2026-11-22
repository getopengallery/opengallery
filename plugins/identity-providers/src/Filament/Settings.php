<?php

namespace Plugins\IdentityProviders\Filament;

use App\Enums\ImagePreview;
use App\Extensions\PluginSettings;
use App\Filament\Forms\Components\ImageUpload;
use App\Filament\Pages\PluginSettingsPage;
use App\Models\Group;
use App\Support\ActivityLog;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugins\IdentityProviders\IdentityProvider;
use Plugins\IdentityProviders\ProviderRepository;

/**
 * Admin → Plugins → Identity providers ⚙
 */
class Settings extends PluginSettingsPage
{
    protected static string $plugin = ProviderRepository::PLUGIN;

    public function getSubheading(): ?string
    {
        return __('identity-providers::settings.let_people_sign_in_with');
    }

    public function mount(): void
    {
        $providers = collect(app(ProviderRepository::class)->all())
            ->map(fn (IdentityProvider $p) => [
                ...Arr::except($p->config, ['client_secret']),
                'id' => $p->config['id'] ?? (string) Str::uuid(),
                'client_secret' => null,
                'has_secret' => filled($p->config['client_secret'] ?? null),
            ])
            ->values()
            ->all();

        $this->form->fill(['providers' => $providers]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Repeater::make('providers')
                    ->label(__('identity-providers::plugin.name'))
                    ->hiddenLabel()
                    ->addActionLabel(__('identity-providers::settings.add_identity_provider'))
                    ->itemLabel(fn (array $state) => ($state['name'] ?? null) ?: __('identity-providers::settings.new_identity_provider'))
                    ->collapsible()
                    ->collapsed(fn () => app(ProviderRepository::class)->all()->isNotEmpty())
                    ->cloneable(false)
                    ->defaultItems(0)
                    ->schema([
                        Hidden::make('id')->default(fn () => (string) Str::uuid()),

                        Section::make(__('identity-providers::settings.button'))
                            ->description(__('identity-providers::settings.how_provider_appears_on_login'))
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('identity-providers::settings.name'))
                                    ->required()
                                    ->maxLength(60)
                                    ->live(onBlur: true)
                                    ->helperText(__('identity-providers::settings.shown_as_log_in_with')),
                                TextInput::make('key')
                                    ->label(__('identity-providers::settings.identifier'))
                                    ->required()
                                    ->alphaDash()
                                    ->maxLength(40)
                                    ->live(onBlur: true)
                                    ->helperText(__('identity-providers::settings.lowercase_used_in_urls_and')),
                                Textarea::make('description')
                                    ->label(__('identity-providers::settings.description'))
                                    ->rows(2)
                                    ->maxLength(160)
                                    ->helperText(__('identity-providers::settings.optional_line_under_button_for')),
                                ImageUpload::make('icon')
                                    ->label(__('identity-providers::settings.icon'))
                                    ->previewAs(ImagePreview::Icon)
                                    ->previewChip(__('identity-providers::messages.log_in_with_name'), 'name', __('identity-providers::settings.name'))
                                    ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/webp'])
                                    ->maxSize(256)
                                    ->disk('local')
                                    ->directory('plugins/identity-providers')
                                    ->visibility('private')
                                    ->helperText(__('identity-providers::settings.optional_square_shown_at_20')),
                                Grid::make(3)->columnSpanFull()->schema([
                                    Toggle::make('enabled')->label(__('identity-providers::settings.turned_on'))->default(true),
                                    Toggle::make('show_on_login')->label(__('identity-providers::settings.show_on_login_page'))->default(true),
                                    Toggle::make('is_default')
                                        ->label(__('identity-providers::settings.use_as_default_login'))
                                        ->helperText(__('identity-providers::settings.log_in_goes_straight_this')),
                                ]),
                            ]),

                        Section::make(__('identity-providers::settings.connection'))
                            ->columns(2)
                            ->schema([
                                Select::make('type')
                                    ->label(__('identity-providers::settings.type'))
                                    ->options([
                                        IdentityProvider::OIDC => __('identity-providers::settings.openid_connect_keycloak_authentik_entra'),
                                        IdentityProvider::OAUTH2 => __('identity-providers::settings.oauth_2_0_enter_endpoints'),
                                        IdentityProvider::GITHUB => 'GitHub',
                                    ])
                                    ->default(IdentityProvider::OIDC)
                                    ->required()
                                    ->live()
                                    ->columnSpanFull(),
                                TextInput::make('issuer')
                                    ->label(__('identity-providers::settings.issuer_url'))
                                    ->url()
                                    ->placeholder('https://id.example.org/realms/family')
                                    ->helperText(__('identity-providers::settings.endpoints_are_discovered_from_issuer'))
                                    ->required(fn (Get $get) => $get('type') === IdentityProvider::OIDC)
                                    ->visible(fn (Get $get) => $get('type') === IdentityProvider::OIDC)
                                    ->columnSpanFull(),
                                TextInput::make('authorize_url')->label(__('identity-providers::settings.authorization_url'))->url()
                                    ->required(fn (Get $get) => $get('type') === IdentityProvider::OAUTH2)
                                    ->visible(fn (Get $get) => $get('type') === IdentityProvider::OAUTH2),
                                TextInput::make('token_url')->label(__('identity-providers::settings.token_url'))->url()
                                    ->required(fn (Get $get) => $get('type') === IdentityProvider::OAUTH2)
                                    ->visible(fn (Get $get) => $get('type') === IdentityProvider::OAUTH2),
                                TextInput::make('userinfo_url')->label(__('identity-providers::settings.user_info_url'))->url()
                                    ->required(fn (Get $get) => $get('type') === IdentityProvider::OAUTH2)
                                    ->visible(fn (Get $get) => $get('type') === IdentityProvider::OAUTH2),
                                TextInput::make('client_id')->label(__('identity-providers::settings.client_id'))->required(),
                                TextInput::make('client_secret')
                                    ->label(__('identity-providers::settings.client_secret'))
                                    ->password()
                                    ->revealable()
                                    ->placeholder(fn (Get $get) => $get('has_secret') ? __('identity-providers::settings.stored_leave_empty_keep_it') : null)
                                    ->helperText(__('identity-providers::settings.stored_encrypted')),
                                TextInput::make('scopes')
                                    ->label(__('identity-providers::settings.scopes'))
                                    ->placeholder(fn (Get $get) => match ($get('type')) {
                                        IdentityProvider::GITHUB => 'read:user user:email',
                                        IdentityProvider::OAUTH2 => '',
                                        default => 'openid profile email',
                                    })
                                    ->helperText(__('identity-providers::settings.space_separated_leave_empty_for')),
                                Select::make('token_auth')
                                    ->label(__('identity-providers::settings.client_authentication'))
                                    ->options(['post' => __('identity-providers::settings.in_request_body_client_secret'), 'basic' => __('identity-providers::settings.http_basic_client_secret_basic')])
                                    ->default('post')
                                    ->formatStateUsing(fn (?string $state) => $state ?? 'post')
                                    ->selectablePlaceholder(false),
                                TextInput::make('callback')
                                    ->label(__('identity-providers::settings.redirect_uri_register_at_provider'))
                                    ->formatStateUsing(fn (Get $get) => $get('key') ? route('idp.callback', $get('key')) : __('identity-providers::settings.enter_identifier_first'))
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpanFull(),
                                Hidden::make('has_secret')->dehydrated(false),
                            ]),

                        Section::make(__('identity-providers::settings.claims'))
                            ->description(__('identity-providers::settings.which_values_from_provider_use'))
                            ->columns(3)
                            ->collapsed()
                            ->schema([
                                TextInput::make('claims.identifier')->label(__('identity-providers::settings.recognise_people_by'))->placeholder(fn (Get $get) => $this->defaultClaim($get, 'identifier'))
                                    ->helperText(__('identity-providers::settings.must_never_change_for_person')),
                                TextInput::make('claims.name')->label(__('identity-providers::settings.display_name'))->placeholder(fn (Get $get) => $this->defaultClaim($get, 'name')),
                                TextInput::make('claims.username')->label(__('identity-providers::settings.username'))->placeholder(fn (Get $get) => $this->defaultClaim($get, 'username'))
                                    ->helperText(__('identity-providers::settings.used_as_name_when_no')),
                                TextInput::make('claims.email')->label(__('identity-providers::settings.mail'))->placeholder(fn (Get $get) => $this->defaultClaim($get, 'email')),
                                TextInput::make('claims.email_verified')->label(__('identity-providers::settings.mail_verified'))->placeholder(fn (Get $get) => $this->defaultClaim($get, 'email_verified')),
                                TextInput::make('claims.groups')->label(__('identity-providers::settings.groups'))->placeholder(fn (Get $get) => $this->defaultClaim($get, 'groups') ?: 'groups'),
                            ]),

                        Section::make(__('identity-providers::settings.accounts'))
                            ->schema([
                                Toggle::make('create_users')
                                    ->label(__('identity-providers::settings.create_account_when_someone_signs'))
                                    ->helperText(__('identity-providers::settings.independent_site_s_allow_people'))
                                    ->live(),
                                Toggle::make('approve_new_accounts')
                                    ->label(__('identity-providers::settings.approve_new_accounts_automatically'))
                                    ->helperText(__('identity-providers::settings.new_accounts_skip_site_approval'))
                                    ->visible(fn (Get $get) => $get('create_users')),
                                Toggle::make('link_by_email')
                                    ->label(__('identity-providers::settings.connect_existing_account_with_same'))
                                    ->helperText(__('identity-providers::settings.only_when_provider_confirms_address')),
                            ]),

                        Section::make(__('identity-providers::settings.groups'))
                            ->schema([
                                Toggle::make('sync_groups')
                                    ->label(__('identity-providers::settings.update_group_membership_on_every'))
                                    ->live(),
                                Radio::make('group_mode')
                                    ->label(__('identity-providers::settings.how_match_groups'))
                                    ->options([
                                        IdentityProvider::MODE_MAPPED => __('identity-providers::settings.mapping_table_listed_groups_follow'),
                                        IdentityProvider::MODE_BY_NAME => __('identity-providers::settings.by_name_add_people_groups'),
                                    ])
                                    ->default(IdentityProvider::MODE_MAPPED)
                                    ->live()
                                    ->visible(fn (Get $get) => $get('sync_groups')),
                                Repeater::make('group_map')
                                    ->label(__('identity-providers::settings.mapping'))
                                    ->table([
                                        Repeater\TableColumn::make(__('identity-providers::settings.value_in_groups_claim')),
                                        Repeater\TableColumn::make(__('identity-providers::settings.opengallery_group')),
                                    ])
                                    ->schema([
                                        TextInput::make('value')->label(__('identity-providers::settings.value_in_groups_claim'))->required()->placeholder('/family'),
                                        Select::make('group_id')->label(__('identity-providers::settings.opengallery_group'))->options(fn () => Group::orderBy('name')->pluck('name', 'id'))->required()->searchable(),
                                    ])
                                    ->addActionLabel(__('identity-providers::settings.add_mapping'))
                                    ->defaultItems(0)
                                    ->visible(fn (Get $get) => $get('sync_groups') && $get('group_mode') !== IdentityProvider::MODE_BY_NAME),
                                Toggle::make('create_missing_groups')
                                    ->label(__('identity-providers::settings.create_groups_that_do_not'))
                                    ->visible(fn (Get $get) => $get('sync_groups') && $get('group_mode') === IdentityProvider::MODE_BY_NAME),
                                Toggle::make('remove_unlisted_groups')
                                    ->label(__('identity-providers::settings.remove_groups_provider_no_longer_lists'))
                                    ->helperText(__('identity-providers::settings.remove_groups_applies_to_all'))
                                    ->visible(fn (Get $get) => $get('sync_groups') && $get('group_mode') === IdentityProvider::MODE_BY_NAME),
                            ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $items = array_values($this->form->getState()['providers'] ?? []);
        $previous = collect(app(ProviderRepository::class)->all())->mapWithKeys(fn (IdentityProvider $p) => [$p->config['id'] ?? $p->key() => $p->config]);

        $keys = array_map(fn ($item) => Str::lower((string) $item['key']), $items);
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['data.providers' => __('identity-providers::settings.each_provider_needs_its_own')]);
        }
        if (collect($items)->filter(fn ($item) => ($item['is_default'] ?? false) && ($item['enabled'] ?? false))->count() > 1) {
            throw ValidationException::withMessages(['data.providers' => __('identity-providers::settings.only_one_provider_can_be')]);
        }

        $configs = array_map(function (array $item) use ($previous) {
            $old = $previous->get($item['id'] ?? '') ?? $previous->get($item['key']) ?? [];
            $secret = filled($item['client_secret'] ?? null)
                ? PluginSettings::encrypt($item['client_secret'])
                : ($old['client_secret'] ?? null);

            return [...Arr::except($item, ['client_secret', 'has_secret', 'callback']), 'key' => Str::lower($item['key']), 'client_secret' => $secret];
        }, $items);

        app(ProviderRepository::class)->save($configs);
        // Logged as the plugin's name (a translation key, translated when the log is shown).
        ActivityLog::settingsChanged([[ProviderRepository::PLUGIN.'::plugin.name']]);

        $this->mount();

        Notification::make()->title(__('identity-providers::settings.identity_providers_saved'))->success()->send();
    }

    private function defaultClaim(Get $get, string $claim): string
    {
        return (new IdentityProvider(['key' => 'x', 'type' => $get('type')]))->claim($claim);
    }
}
