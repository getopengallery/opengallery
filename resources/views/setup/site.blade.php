<x-setup.layout :step="$step" :reached="$reached" :title="__('Your gallery')">
    <p class="mt-3 text-muted">
        {{ __('The main settings. You can change all of them, and many more, later in the admin area under Settings.') }}
    </p>

    <form method="POST" action="{{ route('setup.site') }}" class="mt-8 space-y-10" x-data="{ delivery: @js(old('mail_delivery', $values['mail_delivery'])) }">
        @csrf

        <section class="space-y-5">
            <div>
                <x-input-label for="name" :value="__('Site name')" />
                <x-text-input id="name" name="name" class="mt-1.5 w-full" :value="old('name', $values['name'])" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="locale" :value="__('Default language')" />
                <select id="locale" name="locale" class="og-input mt-1.5 w-full">
                    @foreach ($locales as $code => $name)
                        <option value="{{ $code }}" @selected(old('locale', $values['locale']) === $code)>{{ $name }}</option>
                    @endforeach
                </select>
                <p class="mt-1.5 text-caption text-muted">{{ __('Used for visitors and for everyone who has not chosen a language in their profile. E-mails go out in the recipient\'s language.') }}</p>
                <x-input-error :messages="$errors->get('locale')" class="mt-2" />
            </div>
        </section>

        <section>
            <h2 class="text-title font-semibold">{{ __('Access') }}</h2>
            <div class="mt-4 space-y-4">
                @foreach ([
                    'public_albums' => [__('Show public albums to visitors who are not logged in'), __('When off, albums marked "Public" are only visible after logging in. Share links for unlisted albums keep working.')],
                    'registration' => [__('Allow people to create accounts'), __('When off, only administrators can add users. Applies to e-mail and OAuth sign-up.')],
                    'require_approval' => [__('New accounts need an administrator\'s approval'), __('People who sign up can log in, but only see a "waiting for approval" page until someone who manages users approves them under Users. Those people are told about every new account.')],
                ] as $field => [$label, $help])
                    <label class="flex gap-3">
                        <input type="hidden" name="{{ $field }}" value="0">
                        <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $values[$field]))
                            class="mt-1 rounded-[4px] border-line bg-canvas text-ink focus:ring-0 focus:ring-offset-0">
                        <span>
                            <span class="block text-sm font-medium">{{ $label }}</span>
                            <span class="block text-caption text-muted">{{ $help }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </section>

        <section>
            <h2 class="text-title font-semibold">{{ __('E-mail') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Needed for password resets, invitations and notices. If you do not have the details at hand, keep "Server configuration" and set it up later.') }}</p>

            <fieldset class="mt-4">
                <legend class="sr-only">{{ __('Send e-mail through') }}</legend>
                <div class="grid gap-2">
                    @foreach ($deliveries as $delivery)
                        <label class="flex cursor-pointer gap-3 rounded-og border border-line p-3 has-[:checked]:border-ink">
                            <input type="radio" name="mail_delivery" value="{{ $delivery->value }}" x-model="delivery"
                                class="mt-1 border-line bg-canvas text-ink focus:ring-0 focus:ring-offset-0"
                                @checked(old('mail_delivery', $values['mail_delivery']) === $delivery->value)>
                            <span>
                                <span class="block text-sm font-medium">{{ $delivery->getLabel() }}</span>
                                <span class="block text-caption text-muted">{{ $delivery->getDescription() }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('mail_delivery')" class="mt-2" />
            </fieldset>

            <div class="mt-5 space-y-5" x-show="delivery === 'smtp'">
                <div class="grid gap-5 sm:grid-cols-[minmax(0,1fr)_7rem]">
                    <div>
                        <x-input-label for="mail_host" :value="__('SMTP server')" />
                        <x-text-input id="mail_host" name="mail_host" class="mt-1.5 w-full" placeholder="smtp.example.org" :value="old('mail_host', $values['mail_host'])" />
                        <x-input-error :messages="$errors->get('mail_host')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="mail_port" :value="__('Port')" />
                        <x-text-input id="mail_port" name="mail_port" type="number" min="1" max="65535" class="mt-1.5 w-full" :value="old('mail_port', $values['mail_port'])" />
                        <x-input-error :messages="$errors->get('mail_port')" class="mt-2" />
                    </div>
                </div>
                <div>
                    <x-input-label for="mail_scheme" :value="__('Encryption')" />
                    <select id="mail_scheme" name="mail_scheme" class="og-input mt-1.5 w-full">
                        <option value="smtp" @selected(old('mail_scheme', $values['mail_scheme']) === 'smtp')>{{ __('STARTTLS (usually port 587)') }}</option>
                        <option value="smtps" @selected(old('mail_scheme', $values['mail_scheme']) === 'smtps')>{{ __('SSL/TLS (usually port 465)') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="mail_username" :value="__('User name')" />
                    <x-text-input id="mail_username" name="mail_username" class="mt-1.5 w-full" autocomplete="off" :value="old('mail_username', $values['mail_username'])" />
                    <x-input-error :messages="$errors->get('mail_username')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="mail_password" :value="__('Password')" />
                    <x-text-input id="mail_password" name="mail_password" type="password" class="mt-1.5 w-full" autocomplete="new-password" />
                    @if ($hasMailPassword)
                        <p class="mt-1.5 text-caption text-muted">{{ __('Saved. Leave empty to keep it.') }}</p>
                    @endif
                    <x-input-error :messages="$errors->get('mail_password')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="mail_from_address" :value="__('Sender address')" />
                    <x-text-input id="mail_from_address" name="mail_from_address" type="email" class="mt-1.5 w-full" :value="old('mail_from_address', $values['mail_from_address'])" />
                    <p class="mt-1.5 text-caption text-muted">{{ __('Leave empty to use the server configuration.') }}</p>
                    <x-input-error :messages="$errors->get('mail_from_address')" class="mt-2" />
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-title font-semibold">{{ __('Background jobs') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Regular housekeeping, such as deleting old activity log entries and unfinished uploads.') }}</p>

            <fieldset class="mt-4">
                <legend class="sr-only">{{ __('Start background jobs with') }}</legend>
                <div class="grid gap-2">
                    @foreach ($jobModes as $mode)
                        <label class="flex cursor-pointer gap-3 rounded-og border border-line p-3 has-[:checked]:border-ink">
                            <input type="radio" name="background_jobs" value="{{ $mode->value }}"
                                class="mt-1 border-line bg-canvas text-ink focus:ring-0 focus:ring-offset-0"
                                @checked(old('background_jobs', $values['background_jobs']) === $mode->value)>
                            <span>
                                <span class="block text-sm font-medium">{{ $mode->getLabel() }}</span>
                                <span class="block text-caption text-muted">{{ $mode->getDescription() }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('background_jobs')" class="mt-2" />
            </fieldset>
        </section>

        <section x-data="{ checks: @js((bool) old('update_checks', $values['update_checks'])) }">
            <h2 class="text-title font-semibold">{{ __('Updates') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Once a day the gallery asks GitHub whether a newer version exists. GitHub sees your server\'s address; nothing about your gallery is sent. Nothing is downloaded or installed automatically.') }}</p>

            <label class="mt-4 flex gap-3">
                <input type="hidden" name="update_checks" value="0">
                <input type="checkbox" name="update_checks" value="1" x-model="checks" @checked(old('update_checks', $values['update_checks']))
                    class="mt-1 rounded-[4px] border-line bg-canvas text-ink focus:ring-0 focus:ring-offset-0">
                <span class="block text-sm font-medium">{{ __('Check for new versions') }}</span>
            </label>
            <x-input-error :messages="$errors->get('update_checks')" class="mt-2" />

            <fieldset class="mt-4" x-show="checks">
                <legend class="sr-only">{{ __('Update channel') }}</legend>
                <div class="grid gap-2">
                    @foreach ($updateChannels as $channel)
                        <label class="flex cursor-pointer gap-3 rounded-og border border-line p-3 has-[:checked]:border-ink">
                            <input type="radio" name="update_channel" value="{{ $channel->value }}"
                                class="mt-1 border-line bg-canvas text-ink focus:ring-0 focus:ring-offset-0"
                                @checked(old('update_channel', $values['update_channel']) === $channel->value)>
                            <span>
                                <span class="block text-sm font-medium">{{ $channel->getLabel() }}</span>
                                <span class="block text-caption text-muted">{{ $channel->getDescription() }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('update_channel')" class="mt-2" />
            </fieldset>
        </section>

        <x-primary-button>{{ __('Save and continue') }}</x-primary-button>
    </form>
</x-setup.layout>
