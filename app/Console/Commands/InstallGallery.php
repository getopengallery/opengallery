<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\SearchEngines;
use App\Support\Setup\Installation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('gallery:install {--name=} {--email=} {--password=}')]
#[Description('Run migrations, seed roles & permissions, create the first super administrator and close the web setup')]
class InstallGallery extends Command
{
    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        if (SearchEngines::removeLegacyRobotsFile()) {
            $this->components->warn('public/robots.txt was changed by hand, so the generated one (Admin → Settings → Search engines) is not served. Delete the file to use it.');
        }

        if (User::role(Role::SuperAdmin->value)->exists()) {
            Installation::markInstalled();
            $this->components->info('A super administrator already exists; skipping account creation.');

            return self::SUCCESS;
        }

        $data = [
            'name' => $this->option('name') ?? text('Administrator name', required: true),
            'email' => $this->option('email') ?? text('Administrator e-mail', required: true),
            'password' => $this->option('password') ?? password('Password (min. 12 characters)', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = Installation::createSuperAdmin($data);
        Installation::markInstalled();

        $this->components->info("Super administrator {$user->email} created. Log in at ".url('/admin'));

        return self::SUCCESS;
    }
}
