<?php

use App\Enums\Role;
use App\Filament\Pages\TestMailbox;
use App\Models\TestMail;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('shows the mailbox only to super administrators, who can read and empty it', function () {
    useTestMailbox();
    $mail = TestMail::create(['to' => 'grace@example.com', 'subject' => 'Your account', 'html' => '<p>Choose your password</p>', 'created_at' => now()]);

    $this->actingAs(userWithRole(Role::Admin))
        ->get(TestMailbox::getUrl())
        ->assertForbidden();

    actingAsRole(Role::SuperAdmin);
    $this->get(TestMailbox::getUrl())->assertOk()->assertSee('Your account');
    Livewire::test(TestMailbox::class)
        ->mountAction(TestAction::make('open')->table($mail))
        ->assertMountedActionModalSeeHtml('sandbox="allow-popups allow-popups-to-escape-sandbox"')
        ->assertMountedActionModalSee('Choose your password');
    Livewire::test(TestMailbox::class)->callAction(TestAction::make('clear')->table());

    expect(TestMail::count())->toBe(0);
});
