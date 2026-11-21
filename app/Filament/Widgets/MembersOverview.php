<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Users\UserResource;
use App\Support\Dashboard\MemberStats;

/**
 * For people who manage users: accounts waiting for approval, open and
 * expired invitations, unconfirmed addresses and the newest members.
 */
class MembersOverview extends DashboardWidget
{
    protected string $view = 'filament.widgets.members-overview';

    public static function dashboardLabel(): string
    {
        return __('Members');
    }

    public static function dashboardDescription(): ?string
    {
        return __('Accounts waiting for approval, invitations and new members.');
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::ManageUsers);
    }

    /**
     * What waits for someone who manages users, most urgent first; empty
     * when nothing does.
     *
     * @param  array{waiting: int, invited: int, invitationsExpired: int, unconfirmed: ?int, joined: int}  $counts
     * @return list<array{icon: string, tone: string, text: string, url: ?string, link: ?string}>
     */
    protected function todos(array $counts): array
    {
        $list = fn (array $filters): string => UserResource::getUrl('index', ['filters' => $filters]);

        return array_values(array_filter([
            $counts['waiting'] > 0 ? ['icon' => 'heroicon-o-user-plus', 'tone' => 'warning', 'text' => trans_choice(':count account waits for approval|:count accounts wait for approval', $counts['waiting']), 'url' => $list(['awaiting_approval' => ['isActive' => true]]), 'link' => __('Review')] : null,
            $counts['invitationsExpired'] > 0 ? ['icon' => 'heroicon-o-envelope', 'tone' => 'muted', 'text' => trans_choice(':count invitation expired before it was used. Send a new one from the user’s page.|:count invitations expired before they were used. Send new ones from the users’ pages.', $counts['invitationsExpired']), 'url' => null, 'link' => null] : null,
            ($counts['unconfirmed'] ?? 0) > 0 ? ['icon' => 'heroicon-o-at-symbol', 'tone' => 'muted', 'text' => trans_choice(':count person has not confirmed their e-mail address|:count people have not confirmed their e-mail address', $counts['unconfirmed']), 'url' => $list(['email_verified_at' => ['value' => false]]), 'link' => __('Show')] : null,
            $counts['invited'] > 0 ? ['icon' => 'heroicon-o-paper-airplane', 'tone' => 'muted', 'text' => trans_choice(':count invitation has not been accepted yet|:count invitations have not been accepted yet', $counts['invited']), 'url' => null, 'link' => null] : null,
        ]));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $counts = MemberStats::counts();

        return [
            'todos' => $this->todos($counts),
            'joined' => $counts['joined'],
            'joinedUrl' => UserResource::getUrl('index', ['filters' => ['registered' => ['from' => now()->subDays(MemberStats::NEW_MEMBER_DAYS)->toDateString()]]]),
            'days' => MemberStats::NEW_MEMBER_DAYS,
            ...MemberStats::newest(static::viewer()),
            'usersUrl' => UserResource::getUrl(),
        ];
    }
}
