<section class="space-y-6">
    <header>
        <h2 class="text-title font-semibold text-ink">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm text-muted">
            {{ __('Deleting your account is permanent. Your albums and their photos are deleted with it. To keep your albums, ask an administrator to give them to someone else first.') }}
            @if (($uploadedPhotos ?? 0) > 0)
                {{ __('To keep a copy of your photos, use "Download my photos" above.') }}
            @endif
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >{{ __('Delete Account') }}</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-title font-semibold text-ink">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="mt-1 text-sm text-muted">
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </p>

            @if ($ownedAlbums->isNotEmpty())
                <div class="mt-4 rounded-og border border-danger/40 p-4 text-sm text-ink">
                    <p class="font-semibold">
                        {{ trans_choice('You still own :count album. It will be deleted with your account:|You still own :count albums. They will be deleted with your account:', $ownedAlbums->count()) }}
                    </p>
                    <ul class="mt-2 list-disc ps-5">
                        @foreach ($ownedAlbums->take(10) as $album)
                            <li>{{ $album->title }}</li>
                        @endforeach
                        @if ($ownedAlbums->count() > 10)
                            <li>{{ __('and :count more', ['count' => $ownedAlbums->count() - 10]) }}</li>
                        @endif
                    </ul>
                    <p class="mt-2 text-muted">
                        {{ __('Their photos are deleted too, unless they are also in someone else\'s album. To keep the albums, cancel and ask an administrator to give them to someone else.') }}
                    </p>

                    <label for="delete_albums" class="mt-3 inline-flex items-center gap-2">
                        <input id="delete_albums" type="checkbox" class="og-checkbox" name="delete_albums" value="1">
                        {{ __('Delete my albums and photos with my account') }}
                    </label>

                    <x-input-error :messages="$errors->userDeletion->get('delete_albums')" class="mt-2" />
                </div>
            @endif

            <div class="mt-6">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                <x-text-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-3/4"
                    placeholder="{{ __('Password') }}"
                />

                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    {{ __('Delete Account') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
