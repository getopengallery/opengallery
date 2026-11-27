<x-setup.layout :step="$step" :reached="$reached" :title="__('Database')">
    <p class="mt-3 text-muted">
        {{ __('OpenGallery keeps albums, accounts and settings in a database. Create one in your hosting panel and enter its details here. They are saved in the server configuration file (.env).') }}
    </p>

    @if ($ready)
        <x-auth-session-status class="mt-6" :status="__('The database is set up. Save again only if you want to switch to another database.')" />
    @endif

    @php
        $labels = [
            'mysql' => [__('MySQL'), __('The usual choice on web hosting.')],
            'mariadb' => [__('MariaDB'), __('If your host offers MariaDB.')],
            'sqlite' => [__('SQLite (for trying out)'), __('A single file in the database folder. No setup needed; for testing and small private galleries.')],
        ];
    @endphp

    <form method="POST" action="{{ route('setup.database') }}" class="mt-8 space-y-6" x-data="{ connection: @js(old('connection', $values['connection'])) }">
        @csrf

        <fieldset>
            <legend class="text-sm font-medium text-ink">{{ __('Database type') }}</legend>
            <div class="mt-2 grid gap-2">
                @foreach ($drivers as $driver)
                    <label class="flex cursor-pointer gap-3 rounded-og border border-line p-3 has-[:checked]:border-ink">
                        <input type="radio" name="connection" value="{{ $driver }}" x-model="connection"
                            class="mt-1 border-line bg-canvas text-ink focus:ring-0 focus:ring-offset-0"
                            @checked(old('connection', $values['connection']) === $driver)>
                        <span>
                            <span class="block text-sm font-medium">{{ $labels[$driver][0] }}</span>
                            <span class="block text-caption text-muted">{{ $labels[$driver][1] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('connection')" class="mt-2" />
        </fieldset>

        <div class="space-y-5" x-show="connection !== 'sqlite'">
            <div class="grid gap-5 sm:grid-cols-[minmax(0,1fr)_7rem]">
                <div>
                    <x-input-label for="host" :value="__('Server')" />
                    <x-text-input id="host" name="host" class="mt-1.5 w-full" :value="old('host', $values['host'])" />
                    <x-input-error :messages="$errors->get('host')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="port" :value="__('Port')" />
                    <x-text-input id="port" name="port" type="number" min="1" max="65535" class="mt-1.5 w-full" :value="old('port', $values['port'])" />
                    <x-input-error :messages="$errors->get('port')" class="mt-2" />
                </div>
            </div>
            <div>
                <x-input-label for="database" :value="__('Database name')" />
                <x-text-input id="database" name="database" class="mt-1.5 w-full" :value="old('database', $values['database'])" />
                <x-input-error :messages="$errors->get('database')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="username" :value="__('User name')" />
                <x-text-input id="username" name="username" class="mt-1.5 w-full" autocomplete="off" :value="old('username', $values['username'])" />
                <x-input-error :messages="$errors->get('username')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" name="password" type="password" class="mt-1.5 w-full" autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
        </div>

        <div>
            <x-primary-button>{{ __('Set up database') }}</x-primary-button>
            <p class="mt-2 text-caption text-muted">{{ __('Creating the tables can take a moment.') }}</p>
        </div>
    </form>
</x-setup.layout>
