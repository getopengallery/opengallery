<?php

use App\Enums\BackgroundJobMode;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\BackgroundJobSettings;
use App\Support\BackgroundJobs;
use App\Support\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(fn () => actingAsRole(Role::Admin));

it('saves what starts the background jobs', function () {
    Livewire::test(BackgroundJobSettings::class)
        ->set('data.background_jobs', BackgroundJobMode::Webcron->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(SiteSettings::backgroundJobs())->toBe(BackgroundJobMode::Webcron);
});

it('shows the cron line of this installation and warns until the cron job runs', function () {
    Livewire::test(BackgroundJobSettings::class)
        ->set('data.background_jobs', BackgroundJobMode::Cron->value)
        ->assertSet('data.cron_command', '* * * * * php '.base_path('artisan').' schedule:run')
        ->assertSee('No cron job has started the background jobs in the last hour.');

    $this->artisan('gallery:background-jobs');

    Livewire::test(BackgroundJobSettings::class)
        ->set('data.background_jobs', BackgroundJobMode::Cron->value)
        ->assertDontSee('No cron job has started the background jobs in the last hour.');
});

it('replaces the webcron address, so the old one stops working', function () {
    chooseBackgroundJobs(BackgroundJobMode::Webcron);
    $old = BackgroundJobs::webcronUrl();

    Livewire::test(BackgroundJobSettings::class)
        ->callAction(TestAction::make('renewWebcronAddress')->schemaComponent('webcron_url', schema: 'form'))
        ->assertSet('data.webcron_url', fn (string $url) => $url !== $old);

    $this->get($old)->assertNotFound();
    $this->get(BackgroundJobs::webcronUrl())->assertOk();
});
