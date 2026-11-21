<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;

/**
 * Base of the admin dashboard widgets. Each widget's canView() decides who
 * sees it (Filament also checks it before mounting and on every update);
 * build it on viewer(), so nobody without "access admin panel" gets one.
 * Widgets reach the dashboard through App\Extensions\DashboardWidgets; how
 * each person arranges them is App\Support\Dashboard\DashboardLayout.
 */
abstract class DashboardWidget extends Widget
{
    protected static bool $isLazy = false;

    /** Size chosen for this person's dashboard (DashboardLayout::SIZES); null = defaultSize(). */
    public ?string $dashboardSize = null;

    /** Name in "Customize dashboard". */
    public static function dashboardLabel(): string
    {
        return Str::headline(class_basename(static::class));
    }

    /** One line in "Customize dashboard" on what the widget shows. */
    public static function dashboardDescription(): ?string
    {
        return null;
    }

    /** Size until the person picks another: DashboardLayout::NORMAL, WIDE or FULL. */
    public static function defaultSize(): string
    {
        return DashboardLayout::NORMAL;
    }

    /** Normal: one column; wide: two (the whole row on medium screens); full: the whole row. */
    public function getColumnSpan(): int|string|array
    {
        return match (DashboardLayout::validSize($this->dashboardSize) ?? static::defaultSize()) {
            DashboardLayout::FULL => 'full',
            DashboardLayout::WIDE => DashboardLayout::WIDE_SPAN,
            default => 1,
        };
    }

    /** The signed-in user, if they may open the admin. */
    protected static function viewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->can(Permission::AccessAdmin->value) ? $user : null;
    }

    /** Whether the viewer holds at least one of $permissions. */
    protected static function viewerCan(Permission ...$permissions): bool
    {
        return static::viewer()?->canAny(array_map(fn (Permission $permission) => $permission->value, $permissions)) ?? false;
    }
}
