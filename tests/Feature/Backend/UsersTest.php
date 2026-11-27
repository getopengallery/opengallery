<?php

use App\Enums\ActivityType;
use App\Enums\Role as BuiltInRole;
use App\Filament\Actions\ActionIcons;
use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\SessionsRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Models\ActivityEvent;
use App\Models\Album;
use App\Models\Group;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Notifications\AccountApproved;
use App\Notifications\GroupAlbumsShared;
use App\Notifications\PasswordChanged;
use App\Notifications\SessionsSignedOut;
use App\Notifications\UserInvited;
use App\Support\Avatars;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Text;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake(Avatars::DISK));

describe('as a super admin', function () {
    it('lists each user with roles, groups, dates and picture, opening the user from the row', function (?string $locale) {
        $this->actingAs(userWithRole(BuiltInRole::SuperAdmin, ['locale' => $locale]));
        $role = Role::findByName(BuiltInRole::Uploader->value);
        $group = Group::factory()->create();
        $image = UploadedFile::fake()->image('a.jpg');
        $member = User::factory()->create([
            'email_verified_at' => '2026-03-07 09:05:00',
            'avatar_path' => Avatars::store($image->getRealPath()),
        ]);
        $member->assignRole($role);
        $group->users()->attach($member);
        $approve = TestAction::make('approve')->table()->bulk();

        $page = Livewire::test(ListUsers::class);
        $table = $page->instance()->getTable();
        $columns = $table->getColumns();

        $page->assertCanSeeTableRecords([$member])
            // Dates read DD.MM.YYYY HH:MM in every language.
            ->assertSee('07.03.2026 09:05')
            ->assertDontSee('Mar 7, 2026')
            // The roles and groups named in a row link to their pages.
            ->assertSeeHtml(RoleResource::getUrl('edit', ['record' => $role]))
            ->assertSeeHtml(GroupResource::getUrl('edit', ['record' => $group]))
            ->assertTableColumnStateSet('avatar', Avatars::url($member), $member)
            ->assertActionDoesNotExist(TestAction::make('edit')->table($member))
            ->assertActionHasIcon($approve, ActionIcons::APPROVE)
            ->assertActionHasColor($approve, 'success');

        expect($table->getRecordUrl($member))->toBe(UserResource::getUrl('edit', ['record' => $member]))
            ->and($table->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
            ->and($table->hasDeferredFilters())->toBeFalse()
            ->and($columns['roles.name']->isBadge())->toBeTrue()
            ->and($columns['groups.name']->isBadge())->toBeTrue()
            ->and($columns['name']->isBadge())->toBeFalse()
            ->and($columns['email']->isBadge())->toBeFalse();
    })->with(['site default' => [null], 'German' => ['de']]);

    it('filters users by role, group and verified address', function () {
        $this->actingAs(userWithRole(BuiltInRole::SuperAdmin));
        $team = Group::factory()->create();
        $verifiedUploader = userWithRole(BuiltInRole::Uploader);
        $unverifiedMember = tap(User::factory()->unverified()->create())->assignRole(BuiltInRole::User->value);
        $team->users()->attach($unverifiedMember);

        Livewire::test(ListUsers::class)
            ->filterTable('roles', [Role::findByName(BuiltInRole::Uploader->value)->id])
            ->assertCanSeeTableRecords([$verifiedUploader])
            ->assertCanNotSeeTableRecords([$unverifiedMember])
            ->resetTableFilters()
            ->filterTable('groups', [$team->id])
            ->assertCanSeeTableRecords([$unverifiedMember])
            ->assertCanNotSeeTableRecords([$verifiedUploader])
            ->resetTableFilters()
            ->filterTable('email_verified_at', false)
            ->assertCanSeeTableRecords([$unverifiedMember])
            ->assertCanNotSeeTableRecords([$verifiedUploader]);
    });

    it('lets a super admin give the super admin role', function () {
        $this->actingAs(userWithRole(BuiltInRole::SuperAdmin));
        $target = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $target->id])
            ->fillForm(['roles' => [Role::findByName(BuiltInRole::SuperAdmin->value)->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($target->fresh()->hasRole(BuiltInRole::SuperAdmin->value))->toBeTrue();
    });
});

describe('as an admin', function () {
    beforeEach(function () {
        $this->admin = actingAsRole(BuiltInRole::Admin, ['name' => 'Ada']);
    });

    describe('creating and inviting', function () {
        it('creates a user without a password and e-mails them an invitation', function () {
            mailWorks();

            Livewire::test(CreateUser::class)
                ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => true])
                ->call('create')
                ->assertHasNoFormErrors()
                ->assertNotified('Invitation sent to grace@example.com');

            $grace = User::where('email', 'grace@example.com')->sole();
            Notification::assertSentTo($grace, UserInvited::class, fn (UserInvited $mail) => str_contains($mail->url, '/invitation/') && $mail->invitedBy === 'Ada');
        });

        it('gives the admin the link to pass on when e-mail is not set up', function () {
            config(['mail.default' => 'log']);
            Notification::fake();

            Livewire::test(CreateUser::class)
                ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => true])
                ->call('create')
                ->assertHasNoFormErrors()
                ->assertNotified('The invitation was not e-mailed');

            Notification::assertNothingSent();
        });

        it('asks for a password when no invitation is sent', function () {
            mailWorks();

            Livewire::test(CreateUser::class)
                ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => false])
                ->call('create')
                ->assertHasFormErrors(['password' => 'required']);
        });

        it('counts people added by an administrator as confirmed', function () {
            Livewire::test(CreateUser::class)
                ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => false, 'password' => 'a-long-password'])
                ->call('create')
                ->assertHasNoFormErrors();

            expect(User::where('email', 'grace@example.com')->sole()->email_verified_at)->not->toBeNull();
        });

        it('sends a new password link from the user page', function () {
            mailWorks();
            $grace = User::factory()->create(['email' => 'grace@example.com']);

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->callAction('sendInvitation')
                ->assertNotified('Invitation sent to grace@example.com');

            Notification::assertSentTo($grace, UserInvited::class);
        });
    });

    describe('editing a user', function () {
        it('saves a changed "E-mail verified at" on the user page', function () {
            $grace = User::factory()->create();

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->fillForm(['email_verified_at' => null])
                ->call('save')
                ->assertHasNoFormErrors();

            expect($grace->fresh()->email_verified_at)->toBeNull();
        });

        it('approves an account from the users list and tells the person', function () {
            mailWorks();
            $grace = tap(User::factory()->create())->forceFill(['awaiting_approval' => true]);
            $grace->save();

            Livewire::test(ListUsers::class)
                ->callAction(TestAction::make('approve')->table($grace))
                ->assertNotified("{$grace->name} was approved");

            expect($grace->fresh()->awaiting_approval)->toBeFalse();
            Notification::assertSentTo($grace, AccountApproved::class);

            $this->actingAs($grace->fresh())->get(route('dashboard'))->assertOk();
        });

        it('names the administrator who changed someone\'s password', function () {
            mailWorks();
            $grace = User::factory()->create();

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->fillForm(['password' => 'a-new-long-password'])
                ->call('save')
                ->assertHasNoFormErrors();

            Notification::assertSentTo($grace, PasswordChanged::class, fn (PasswordChanged $notice) => $notice->changedBy === 'Ada');
        });

        it('tells someone about the albums their new group gives them only when the switch is on', function (bool $tell) {
            Notification::fake();
            [, $group] = groupWithAlbum();
            $grace = User::factory()->create();

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->fillForm(['groups' => [$group->id], 'notify_group_access' => $tell])
                ->call('save')
                ->assertHasNoFormErrors();

            $tell
                ? Notification::assertSentTo($grace, GroupAlbumsShared::class)
                : Notification::assertNotSentTo($grace, GroupAlbumsShared::class);
        })->with([true, false]);

        it('lets admins change a user\'s picture and logs it without the file name', function () {
            $user = User::factory()->create();

            Livewire::test(EditUser::class, ['record' => $user->id])
                ->fillForm(['avatar_path' => UploadedFile::fake()->image('face.png', 300, 500)])
                ->call('save')
                ->assertHasNoErrors();

            $path = $user->fresh()->avatar_path;
            expect($path)->toEndWith('.webp');
            Storage::disk(Avatars::DISK)->assertExists($path);

            $event = ActivityEvent::query()->where('subject_id', $user->id)->latest('id')->first();
            expect($event->details())->toContain('Profile picture changed')
                ->and(json_encode($event->properties))->not->toContain($path);
        });

        it('shows the current picture in the admin form', function () {
            $image = UploadedFile::fake()->image('a.jpg');
            $user = User::factory()->create(['avatar_path' => Avatars::store($image->getRealPath())]);

            $state = Livewire::test(EditUser::class, ['record' => $user->id])->get('data.avatar_path');

            expect(array_values($state))->toBe([$user->avatar_path]);
        });
    });

    describe('storage limits', function () {
        it('has no storage section while limits are off, and leaves the stored limits alone on save', function () {
            $grace = giveStorageLimit(User::factory()->create(), 2 * 1024 ** 3);

            Livewire::test(CreateUser::class)->assertDontSee('Unlimited storage');

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->assertDontSee('Unlimited storage')
                ->fillForm(['name' => 'Grace H.', 'storage_unlimited' => true, 'storage_quota_gb' => 7])
                ->call('save')
                ->assertHasNoFormErrors();

            expect($grace->fresh()->name)->toBe('Grace H.')
                ->and($grace->fresh()->storage_unlimited)->toBeFalse()
                ->and($grace->fresh()->storage_quota_bytes)->toBe(2 * 1024 ** 3);
        });

        it('creates a user with their own limit, with no limit, or following the site default', function (array $storage, bool $unlimited, ?int $bytes) {
            useStorageLimits(defaultGb: 7);

            Livewire::test(CreateUser::class)
                ->assertSee('Unlimited storage')
                ->assertSee('Default (7 GB)')
                ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => false, 'password' => 'a-long-password', ...$storage])
                ->call('create')
                ->assertHasNoFormErrors();

            $grace = User::where('email', 'grace@example.com')->sole();
            expect($grace->storage_unlimited)->toBe($unlimited)
                ->and($grace->storage_quota_bytes)->toBe($bytes);
        })->with([
            'own limit' => [['storage_quota_gb' => 3], false, 3 * 1024 ** 3],
            'unlimited' => [['storage_unlimited' => true, 'storage_quota_gb' => 3], true, null],
            'empty, so the default' => [['storage_quota_gb' => null], false, null],
        ]);

        it('rejects a storage limit outside the allowed range', function (int $gb, string $rule) {
            useStorageLimits();

            Livewire::test(CreateUser::class)
                ->fillForm(['name' => 'Grace', 'email' => 'grace@example.com', 'send_invitation' => false, 'password' => 'a-long-password', 'storage_quota_gb' => $gb])
                ->call('create')
                ->assertHasFormErrors(['storage_quota_gb' => $rule]);

            expect(User::where('email', 'grace@example.com')->exists())->toBeFalse();
        })->with(['zero' => [0, 'min'], 'too large' => [1_000_001, 'max']]);

        it('fills the form from what is stored', function (?int $bytes, bool $unlimited, ?int $gb) {
            useStorageLimits();
            $grace = giveStorageLimit(User::factory()->create(), $bytes, $unlimited);

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->assertFormSet(['storage_unlimited' => $unlimited, 'storage_quota_gb' => $gb]);
        })->with([
            'own limit' => [4 * 1024 ** 3, false, 4],
            'unlimited' => [null, true, null],
            'default' => [null, false, null],
        ]);

        it('switches a user to unlimited storage and back to the default', function () {
            useStorageLimits();
            $grace = giveStorageLimit(User::factory()->create(), 2 * 1024 ** 3);

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->fillForm(['storage_unlimited' => true])
                ->call('save')
                ->assertHasNoFormErrors();

            expect($grace->fresh()->storage_unlimited)->toBeTrue()
                ->and($grace->fresh()->storage_quota_bytes)->toBeNull();

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->assertFormSet(['storage_unlimited' => true])
                ->fillForm(['storage_unlimited' => false, 'storage_quota_gb' => null])
                ->call('save')
                ->assertHasNoFormErrors();

            expect($grace->fresh()->storage_unlimited)->toBeFalse()
                ->and($grace->fresh()->storage_quota_bytes)->toBeNull();
        });

        it('empties the limit with "Reset to default" so saving makes the user follow the default', function () {
            useStorageLimits();
            $grace = giveStorageLimit(User::factory()->create(), 2 * 1024 ** 3);
            $reset = TestAction::make('resetStorageQuota')->schemaComponent('storage_quota_gb', schema: 'form');

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->assertActionVisible($reset)
                ->callAction($reset)
                ->assertFormSet(['storage_quota_gb' => null])
                ->call('save')
                ->assertHasNoFormErrors();

            expect($grace->fresh()->storage_quota_bytes)->toBeNull()
                ->and($grace->fresh()->storage_unlimited)->toBeFalse();
        });

        it('keeps the exact stored bytes when the limit shown in GB is not changed', function () {
            useStorageLimits();
            $bytes = 2 * 1024 ** 3 + 123;
            $grace = giveStorageLimit(User::factory()->create(), $bytes);

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->fillForm(['name' => 'Grace H.'])
                ->call('save');

            expect($grace->fresh()->storage_quota_bytes)->toBe($bytes);

            Livewire::test(EditUser::class, ['record' => $grace->getRouteKey()])
                ->fillForm(['storage_quota_gb' => 5])
                ->call('save');

            expect($grace->fresh()->storage_quota_bytes)->toBe(5 * 1024 ** 3);
        });
    });

    describe('deleting a user', function () {
        it('lists the albums and gives them to the person deleting by default', function () {
            $user = User::factory()->create();
            $album = Album::factory()->for($user, 'owner')->create(['title' => 'Summer camp']);
            $trashed = tap(Album::factory()->for($user, 'owner')->create())->delete();
            $unsorted = Photo::factory()->inAlbum(Album::inbox($user))->create(['uploader_id' => $user->id]);
            $onPage = Photo::factory()->inAlbum(Album::pageImages($user))->create(['uploader_id' => $user->id]);

            $page = Livewire::test(EditUser::class, ['record' => $user->id])
                ->mountAction(DeleteAction::class)
                ->assertSchemaStateSet(['new_owner_id' => $this->admin->id], 'mountedActionSchema0');

            $notice = collect($page->instance()->getSchema('mountedActionSchema0')->getFlatComponents())
                ->first(fn ($component) => $component instanceof Text)
                ->getContent();
            expect($notice)->toStartWith('Their albums (3):')->toContain('Summer camp', 'Unsorted uploads')->not->toContain('Page images');

            $page->callMountedAction()->assertHasNoFormErrors();

            expect($user->fresh())->toBeNull()
                ->and($album->fresh()->owner_id)->toBe($this->admin->id)
                ->and($album->fresh()->trashed())->toBeFalse()
                ->and($trashed->fresh()->owner_id)->toBe($this->admin->id)
                // Their system albums are merged into the new owner's own.
                ->and($unsorted->fresh()->albums->modelKeys())->toBe([Album::inbox($this->admin)->id])
                ->and($onPage->fresh()->albums->modelKeys())->toBe([Album::pageImages($this->admin)->id])
                ->and(Album::withTrashed()->where('owner_id', $this->admin->id)->whereNotNull('system_album')->count())->toBe(2);
        });

        it('gives the albums to another chosen person', function () {
            $user = User::factory()->create();
            $heir = User::factory()->create();
            $album = Album::factory()->for($user, 'owner')->create();

            Livewire::test(EditUser::class, ['record' => $user->id])
                ->callAction(DeleteAction::class, ['new_owner_id' => $heir->id])
                ->assertHasNoFormErrors();

            expect($album->fresh()->owner_id)->toBe($heir->id);
        });

        it('deletes a person from their row in the users list, asking who gets their albums', function () {
            $user = User::factory()->create();
            $heir = User::factory()->create();
            $album = Album::factory()->for($user, 'owner')->create();

            Livewire::test(ListUsers::class)
                ->callAction(TestAction::make('delete')->table($user), ['new_owner_id' => $heir->id])
                ->assertHasNoFormErrors();

            expect($user->fresh())->toBeNull()
                ->and($album->fresh()->owner_id)->toBe($heir->id);
        });

        it('moves the albums to the trash without a new owner, but keeps "Page images"', function () {
            $user = User::factory()->create();
            $album = Album::factory()->for($user, 'owner')->create();
            $inbox = Album::inbox($user);
            $pageImages = Album::pageImages($user);

            Livewire::test(EditUser::class, ['record' => $user->id])
                ->callAction(DeleteAction::class, ['new_owner_id' => null])
                ->assertHasNoFormErrors();

            $album = Album::withTrashed()->find($album->id);

            expect($user->fresh())->toBeNull()
                ->and($album->trashed())->toBeTrue()
                ->and($album->owner_id)->toBeNull()
                ->and(Album::withTrashed()->find($inbox->id)->trashed())->toBeTrue()
                ->and($pageImages->fresh()->trashed())->toBeFalse()
                ->and($pageImages->fresh()->owner_id)->toBeNull();
        });

        it('refuses someone who is being deleted as the new owner', function () {
            $first = User::factory()->create();
            $second = User::factory()->create();
            Album::factory()->for($first, 'owner')->create();

            Livewire::test(ListUsers::class)
                ->selectTableRecords([$first->id, $second->id])
                ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk(), ['new_owner_id' => $second->id])
                ->assertHasFormErrors(['new_owner_id']);

            expect($first->fresh())->not->toBeNull()
                ->and($second->fresh())->not->toBeNull();
        });

        it('gives the albums of everyone deleted in bulk to the new owner', function () {
            $first = User::factory()->create();
            $second = User::factory()->create();
            $albums = collect([$first, $second])->map(fn (User $user) => Album::factory()->for($user, 'owner')->create());

            Livewire::test(ListUsers::class)
                ->selectTableRecords([$first->id, $second->id])
                ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
                ->assertHasNoFormErrors();

            expect(User::whereKey([$first->id, $second->id])->exists())->toBeFalse()
                ->and($albums->map(fn (Album $album) => $album->fresh()->owner_id)->unique()->all())->toBe([$this->admin->id]);
        });
    });
});

describe('as a user manager', function () {
    beforeEach(function () {
        $this->manager = userManager();
        $this->actingAs($this->manager);
    });

    it('names people, roles and groups as plain text to those who may not open them', function () {
        $member = userWithRole(BuiltInRole::Uploader);
        $group = Group::factory()->create();
        $group->users()->attach($member);

        Livewire::test(ListUsers::class)
            ->assertSee($group->name)
            ->assertDontSeeHtml(RoleResource::getUrl('edit', ['record' => Role::findByName(BuiltInRole::Uploader->value)]))
            ->assertDontSeeHtml(GroupResource::getUrl('edit', ['record' => $group]));
    });

    it('lets a user manager edit and delete only people whose roles they could give', function (?string $role, bool $allowed) {
        $target = User::factory()->create();

        if ($role) {
            $target->assignRole($role);
        }

        expect($this->manager->can('update', $target))->toBe($allowed)
            ->and($this->manager->can('delete', $target))->toBe($allowed);
    })->with([
        'no role' => [null, true],
        'user (fewer permissions)' => [BuiltInRole::User->value, true],
        'same custom role' => ['user manager', true],
        'uploader (permissions they lack)' => [BuiltInRole::Uploader->value, false],
        'admin' => [BuiltInRole::Admin->value, false],
        'super admin' => [BuiltInRole::SuperAdmin->value, false],
    ]);

    it('refuses to open the edit page of a super admin for a user manager', function () {
        Livewire::test(EditUser::class, ['record' => userWithRole(BuiltInRole::SuperAdmin)->id])
            ->assertForbidden();
    });

    it('refuses roles above the user manager\'s own permissions', function (string $role) {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New person',
                'email' => 'new@example.com',
                'password' => 'a-long-enough-password',
                'roles' => [Role::findByName($role)->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['roles.0' => 'The selected roles is invalid.']);

        expect(User::where('email', 'new@example.com')->exists())->toBeFalse();
    })->with([
        'super admin' => [BuiltInRole::SuperAdmin->value],
        'admin' => [BuiltInRole::Admin->value],
        'own role plus more' => [BuiltInRole::Moderator->value],
    ]);

    it('lets a user manager give roles within their own permissions, themselves included', function () {
        Livewire::test(EditUser::class, ['record' => $this->manager->id])
            ->fillForm(['roles' => [Role::findByName('user manager')->id, Role::findByName(BuiltInRole::User->value)->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->manager->fresh()->getRoleNames()->sort()->values()->all())->toBe(['user', 'user manager']);
    });

    it('skips people the user manager may not manage when deleting in bulk', function () {
        $member = userWithRole(BuiltInRole::User);
        $superAdmin = userWithRole(BuiltInRole::SuperAdmin);

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$member->id, $superAdmin->id])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

        expect(User::find($member->id))->toBeNull()
            ->and(User::find($superAdmin->id))->not->toBeNull();
    });
});

describe('pictures', function () {
    it('falls back to initials drawn locally, without an external avatar service', function () {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $url = (new InitialsAvatarProvider)->get($user);

        expect($url)->toStartWith('data:image/svg+xml;base64,')
            ->and(base64_decode(substr($url, strlen('data:image/svg+xml;base64,'))))->toContain('>AL</text>')
            ->and(filament()->getUserAvatarUrl($user))->toBe($url);
    });
});

describe('the sessions of a user', function () {
    beforeEach(function () {
        useDatabaseSessions();
        mailWorks();
        $this->member = User::factory()->create(['remember_token' => 'known-token']);
        sessionRow($this->member, 'laptop', 'Firefox on macOS');
        sessionRow($this->member, 'phone', 'Safari on iOS');
        sessionRow($this->member, 'old-session', 'Opera on Linux', now()->subYear()->getTimestamp());
        sessionRow(User::factory()->create(), 'strangers-session', 'Chrome on Windows');
    });

    it('lists the active sessions of the person on their edit page', function () {
        $admin = actingAsRole(BuiltInRole::Admin);

        Livewire::test(EditUser::class, ['record' => $this->member->id])
            ->assertSeeLivewire(SessionsRelationManager::class);

        Livewire::test(SessionsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditUser::class])
            ->assertCanSeeTableRecords(UserSession::whereIn('id', ['laptop', 'phone'])->get())
            ->assertCanNotSeeTableRecords(UserSession::whereIn('id', ['old-session', 'strangers-session'])->get())
            ->assertSee('Firefox on macOS')
            ->assertSee('Safari on iOS');

        expect($admin->can('update', $this->member))->toBeTrue();
    });

    it('signs out one session and tells the person', function () {
        $admin = actingAsRole(BuiltInRole::Admin);
        $before = (int) ActivityEvent::max('id');

        Livewire::test(SessionsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditUser::class])
            ->callAction(TestAction::make('signOut')->table(UserSession::find('laptop')))
            ->assertNotified();

        expect(sessionIds($this->member))->toBe(['old-session', 'phone'])
            ->and(sessionIds())->toContain('strangers-session')
            ->and($this->member->fresh()->remember_token)->toBe('known-token');
        Notification::assertSentTo($this->member, SessionsSignedOut::class, fn (SessionsSignedOut $notice) => $notice->signedOutBy === $admin->name);

        $entry = ActivityEvent::where('id', '>', $before)->where('type', ActivityType::SessionsSignedOut)->sole();
        expect($entry->user_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($this->member->id)
            ->and($entry->properties)->toMatchArray(['count' => 1]);
    });

    it('signs out everywhere, replaces the remember token and tells the person', function () {
        $admin = actingAsRole(BuiltInRole::Admin);
        $before = (int) ActivityEvent::max('id');

        Livewire::test(SessionsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => EditUser::class])
            ->callAction(TestAction::make('signOutEverywhere')->table())
            ->assertNotified();

        expect(sessionIds($this->member))->toBe([])
            ->and(sessionIds())->toBe(['strangers-session'])
            ->and($this->member->fresh()->remember_token)->not->toBe('known-token');
        Notification::assertSentTo($this->member, SessionsSignedOut::class, fn (SessionsSignedOut $notice, array $channels) => $notice->signedOutBy === $admin->name && $channels === ['database', 'mail']);

        $entry = ActivityEvent::where('id', '>', $before)->where('type', ActivityType::SessionsSignedOut)->sole();
        expect($entry->user_id)->toBe($admin->id)
            ->and($entry->properties)->toMatchArray(['count' => 3]);
    });

    it('is open to super admins and user managers who may manage the person', function (callable $actor) {
        $this->actingAs($actor());

        expect(SessionsRelationManager::canViewForRecord($this->member, EditUser::class))->toBeTrue();
    })->with([
        'super admin' => [fn () => userWithRole(BuiltInRole::SuperAdmin)],
        'user manager' => [fn () => userManager()],
    ]);

    it('is closed to those who may not manage the person', function (callable $actor) {
        $this->actingAs($actor());
        $admin = userWithRole(BuiltInRole::Admin);
        expect(SessionsRelationManager::canViewForRecord($admin, EditUser::class))->toBeFalse();

        Livewire::test(EditUser::class, ['record' => $admin->id])->assertForbidden();
    })->with([
        'user manager' => [fn () => userManager()],
        'user without access' => [fn () => User::factory()->create()],
    ]);

    it('is not offered when sessions are not stored in the database', function () {
        config(['session.driver' => 'file']);
        $this->actingAs(userWithRole(BuiltInRole::SuperAdmin));

        expect(SessionsRelationManager::canViewForRecord($this->member, EditUser::class))->toBeFalse();
    });
});
