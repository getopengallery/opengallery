<?php

namespace Tests;

use App\Support\Setup\Installation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Holds the installation marker files of this test, never the real storage/app. */
    protected string $installationDirectory;

    protected function setUp(): void
    {
        parent::setUp();

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
