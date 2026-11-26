<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Resources\Albums\AlbumResource;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The admin's first page: the widgets of App\Extensions\DashboardWidgets the
 * person may see, arranged as they chose under "Customize dashboard"
 * (App\Support\Dashboard\DashboardLayout). Each widget still checks its own
 * canView(), so permissions always win over saved preferences.
 */
class Dashboard extends BaseDashboard
{
    /** Collaborators without "Open the admin area" only have the Albums section. */
    public function mount(): void
    {
        if (! auth()->user()?->can(Permission::AccessAdmin->value)) {
            $this->redirect(AlbumResource::getUrl());
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can(Permission::AccessAdmin->value) ?? false;
    }

    /** @return list<WidgetConfiguration> */
    public function getWidgets(): array
    {
        return array_map(
            fn (array $entry) => $entry['widget']::make(['dashboardSize' => $entry['size']]),
            $this->layout()->visible($this->user()),
        );
    }

    /** One column on phones, two from medium screens, three on wide ones. */
    public function getColumns(): array
    {
        return DashboardLayout::GRID_COLUMNS;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getWidgets() === [] ? __('All panels are hidden. Use "Customize dashboard" to show them again.') : null;
    }

    protected function getHeaderActions(): array
    {
        return [$this->customizeAction()];
    }

    public function customizeAction(): Action
    {
        return Action::make('customize')
            ->label(__('Edit'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->tooltip(__('Customize dashboard'))
            ->color('gray')
            ->slideOver()
            ->modalHeading(__('Customize dashboard'))
            ->modalDescription(__('Drag the panels or use the arrows to change their order, choose which to show and how wide they are. Only you see this arrangement.'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (): array => ['widgets' => array_map(fn (array $entry) => [
                'key' => $entry['key'],
                'visible' => ! $entry['hidden'],
                'size' => $entry['size'],
            ], $this->layout()->entries($this->user()))])
            ->schema([
                Repeater::make('widgets')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderableWithButtons()
                    ->itemLabel(fn (array $state): ?string => $this->labelFor($state['key'] ?? null))
                    ->columns(2)
                    ->schema([
                        Hidden::make('key'),
                        Toggle::make('visible')
                            ->label(__('Show'))
                            ->helperText(fn ($get): ?string => $this->descriptionFor($get('key'))),
                        Select::make('size')
                            ->label(__('Width'))
                            ->options([
                                DashboardLayout::NORMAL => __('Normal'),
                                DashboardLayout::WIDE => __('Wide'),
                                DashboardLayout::FULL => __('Full width'),
                            ])
                            // On phones every panel is one column wide.
                            ->helperText(__('Applies to larger screens.'))
                            ->selectablePlaceholder(false),
                    ]),
            ])
            ->action(function (array $data): void {
                $this->layout()->save($this->user(), array_map(fn (array $item) => [
                    'key' => $item['key'] ?? null,
                    'hidden' => ! ($item['visible'] ?? true),
                    'size' => $item['size'] ?? null,
                ], array_values($data['widgets'] ?? [])));

                Notification::make()->title(__('Dashboard saved'))->success()->send();
            })
            ->extraModalFooterActions([
                Action::make('resetDashboard')
                    ->label(__('Reset to default'))
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('Reset your dashboard?'))
                    ->modalDescription(__('All panels you may see are shown again, in their standard order and width.'))
                    ->modalSubmitActionLabel(__('Reset'))
                    ->action(function (): void {
                        $this->layout()->reset($this->user());

                        Notification::make()->title(__('Dashboard reset'))->success()->send();
                    })
                    ->cancelParentActions(),
            ]);
    }

    private function labelFor(mixed $key): ?string
    {
        $widget = is_string($key) ? ($this->layout()->available()[$key] ?? null) : null;

        return $widget ? $widget::dashboardLabel() : null;
    }

    private function descriptionFor(mixed $key): ?string
    {
        $widget = is_string($key) ? ($this->layout()->available()[$key] ?? null) : null;

        return $widget ? $widget::dashboardDescription() : null;
    }

    private function layout(): DashboardLayout
    {
        return app(DashboardLayout::class);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
