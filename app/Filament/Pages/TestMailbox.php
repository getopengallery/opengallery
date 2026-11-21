<?php

namespace App\Filament\Pages;

use App\Enums\MailDelivery;
use App\Enums\Role;
use App\Filament\Navigation\NavigationGroup;
use App\Filament\Tables\EmptyState;
use App\Models\TestMail;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The test mailbox: e-mails kept instead of sent while Admin → Settings →
 * E-mail is set to "Test mailbox" (App\Support\MailboxTransport).
 *
 * Only for super administrators: the mails hold working password reset and
 * invitation links for other people's accounts, so reading them is as good
 * as being able to log in as anyone.
 */
class TestMailbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.test-mailbox';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'test-mailbox';

    public function getTitle(): string
    {
        return __('Test mailbox');
    }

    public static function getNavigationLabel(): string
    {
        return __('Test mailbox');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(Role::SuperAdmin->value) ?? false;
    }

    /** Listed while the mailbox is in use or still holds mails. */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && (self::isActive() || TestMail::query()->exists());
    }

    public static function isActive(): bool
    {
        return SiteSettings::mailDelivery() === MailDelivery::Mailbox;
    }

    public function getSubheading(): ?string
    {
        return self::isActive()
            ? trans_choice('E-mails the gallery would have sent. Nobody received them. The newest :count are kept for :days days.|E-mails the gallery would have sent. Nobody received them. The newest :count are kept for :days days.', TestMail::KEEP, ['days' => TestMail::KEEP_DAYS])
            : __('The test mailbox is off (see Settings → E-mail). These are the e-mails it kept earlier.');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(TestMail::query())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('to')
                    ->label(__('To'))
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label(__('Sent'))
                    ->dateTime(),
            ])
            ->recordAction('open')
            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon(Heroicon::OutlinedEnvelopeOpen)
                    ->iconButton()
                    ->modalHeading(fn (TestMail $record): string => $record->subject ?: __('(no subject)'))
                    ->modalContent(fn (TestMail $record) => view('filament.pages.test-mail', ['mail' => $record]))
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
            ])
            ->headerActions([
                Action::make('clear')
                    ->label(__('Delete all'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => TestMail::query()->exists())
                    ->action(function (): void {
                        TestMail::query()->delete();
                        Notification::make()->title(__('Test mailbox emptied'))->success()->send();
                    }),
            ])
            ->tap(fn (Table $table) => EmptyState::apply(
                $table,
                __('No e-mails yet'),
                __('Try "Send a test e-mail" in the settings, or invite someone.'),
                Heroicon::OutlinedInbox,
            ));
    }
}
