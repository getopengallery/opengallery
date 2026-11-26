<?php

namespace Tests;

use App\Support\Setup\Installation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\TestQueueWorker;

abstract class TestCase extends BaseTestCase
{
    /** Holds the installation marker files of this test, never the real storage/app. */
    protected string $installationDirectory;

    /**
     * Seeds the roles and permissions once, when the in-memory database is
     * migrated; every test then runs in a transaction on top of it.
     */
    protected string $seeder = RolesAndPermissionsSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Real waits only slow tests down: the login timebox sleeps through Sleep,
        // and the queue worker would sleep while a delayed job waits.
        Sleep::fake();
        $this->app->extend('queue.worker', fn (Worker $worker) => TestQueueWorker::from($worker));

        $this->installationDirectory = sys_get_temp_dir().'/opengallery-test-'.Str::lower(Str::random(10));
        File::ensureDirectoryExists($this->installationDirectory);
        Installation::useDirectory($this->installationDirectory);
    }

    protected function tearDown(): void
    {
        Installation::useDirectory(null);
        File::deleteDirectory($this->installationDirectory);

        parent::tearDown();
    }
}
