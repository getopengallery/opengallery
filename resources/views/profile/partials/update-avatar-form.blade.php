<section>
    <header>
        <h2 class="text-title font-semibold text-ink">{{ __('Profile picture') }}</h2>
        <p class="mt-1 text-sm text-muted">{{ __('Shown next to your name. JPG, PNG, WebP or GIF, up to :size MB. It is cropped to a square.', ['size' => \App\Support\Avatars::MAX_KB / 1024]) }}</p>
    </header>

    <div class="mt-6 flex items-center gap-5" x-data="{ preview: null }">
        <span class="relative shrink-0">
            <x-avatar :user="$user" size="lg" x-show="! preview" />
            <img x-cloak x-show="preview" :src="preview" alt="" class="og-avatar h-20 w-20 object-cover">
        </span>

        <div class="flex flex-wrap items-center gap-3">
            <form method="post" action="{{ route('profile.avatar.update') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
                @csrf
                <label class="og-btn-secondary cursor-pointer focus-within:ring-2 focus-within:ring-primary">
                    <span x-text="preview ? @js(__('Choose another')) : @js($user->avatar_path ? __('Change picture') : __('Upload picture'))">{{ $user->avatar_path ? __('Change picture') : __('Upload picture') }}</span>
                    <input type="file" name="avatar" accept="{{ implode(',', \App\Support\Avatars::MIME_TYPES) }}" class="sr-only" required
                           x-on:change="const file = $event.target.files[0]; preview = file ? URL.createObjectURL(file) : null">
                </label>
                <x-primary-button x-cloak x-show="preview">{{ __('Save picture') }}</x-primary-button>
            </form>

            @if ($user->avatar_path)
                <form method="post" action="{{ route('profile.avatar.destroy') }}" x-show="! preview">
                    @csrf
                    @method('delete')
                    <button type="submit" class="og-link text-sm text-muted hover:text-ink">{{ __('Remove') }}</button>
                </form>
            @endif

            @if (in_array(session('status'), ['avatar-updated', 'avatar-removed'], true))
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)" class="text-sm text-muted">
                    {{ session('status') === 'avatar-updated' ? __('Picture saved.') : __('Picture removed.') }}
                </p>
            @endif
        </div>
    </div>

    <x-input-error class="mt-2" :messages="$errors->avatar->get('avatar')" />
</section>
