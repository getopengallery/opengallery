<?php

namespace App\Support\Dashboard;

use App\Models\Role;
use App\Models\User;
use App\Support\Invitations;
use App\Support\Mailing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Queries behind the members widget (people with "manage users"): who waits
 * for something, and who joined recently.
 */
class MemberStats
{
    public const NEW_MEMBER_DAYS = 30;

    /**
     * waiting: accounts awaiting approval; invited / invitationsExpired:
     * invitation links still valid or run out; unconfirmed: addresses not
     * confirmed yet (null while the gallery sends no e-mail, as nobody is
     * asked then); joined: new accounts in the last NEW_MEMBER_DAYS days.
     *
     * @return array{waiting: int, invited: int, invitationsExpired: int, unconfirmed: ?int, joined: int}
     */
    public static function counts(): array
    {
        $validSince = now()->subDays(Invitations::validForDays());
        $invitations = DB::table(config('auth.passwords.'.Invitations::BROKER.'.table'))
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as valid, SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) as expired', [$validSince, $validSince])
            ->first();

        $users = User::query()
            ->selectRaw('SUM(CASE WHEN awaiting_approval = 1 THEN 1 ELSE 0 END) as waiting')
            ->selectRaw('SUM(CASE WHEN email_verified_at IS NULL THEN 1 ELSE 0 END) as unconfirmed')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as joined', [now()->subDays(self::NEW_MEMBER_DAYS)])
            ->toBase()
            ->first();

        return [
            'waiting' => (int) $users->waiting,
            'invited' => (int) $invitations->valid,
            'invitationsExpired' => (int) $invitations->expired,
            'unconfirmed' => Mailing::delivers() ? (int) $users->unconfirmed : null,
            'joined' => (int) $users->joined,
        ];
    }

    /**
     * The newest accounts, and which of them $viewer may edit (User::canManage(), in one query).
     *
     * @return array{users: Collection<int, User>, editable: list<int>}
     */
    public static function newest(User $viewer, int $limit = 5): array
    {
        $users = User::query()->latest('created_at')->latest('id')->limit($limit)->get();

        $editable = User::query()
            ->whereKey($users->modelKeys())
            ->whereDoesntHave('roles', fn (Builder $query) => $query->whereNotIn('roles.id', Role::query()->assignableBy($viewer)->select('roles.id')))
            ->pluck('id')
            ->all();

        return ['users' => $users, 'editable' => $editable];
    }
}
