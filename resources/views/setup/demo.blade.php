<x-setup.layout :step="$step" :reached="$reached" :title="__('Demo content')">
    <p class="mt-3 text-muted">
        {{ __('Start with an empty gallery, or add demo content to see what OpenGallery can do.') }}
    </p>

    <form method="POST" action="{{ route('setup.finish') }}" class="mt-8 space-y-6" x-data="{ content: @js(old('content', 'empty')), busy: false }" x-on:submit="busy = true">
        @csrf

        <fieldset>
            <legend class="sr-only">{{ __('Content') }}</legend>
            <div class="grid gap-2">
                <label class="flex cursor-pointer gap-3 rounded-og border border-line p-3 has-[:checked]:border-ink">
                    <input type="radio" name="content" value="empty" x-model="content" @checked(old('content', 'empty') === 'empty')
                        class="mt-1 border-line bg-canvas text-ink focus:ring-0 focus:ring-offset-0">
                    <span>
                        <span class="block text-sm font-medium">{{ __('Start with an empty gallery') }}</span>
                        <span class="block text-caption text-muted">{{ __('For a gallery you are going to use.') }}</span>
                    </span>
                </label>
                <label class="flex cursor-pointer gap-3 rounded-og border border-line p-3 has-[:checked]:border-ink">
                    <input type="radio" name="content" value="demo" x-model="content" @checked(old('content') === 'demo')
                        class="mt-1 border-line bg-canvas text-ink focus:ring-0 focus:ring-offset-0">
                    <span>
                        <span class="block text-sm font-medium">{{ __('Add demo content') }}</span>
                        <span class="block text-caption text-muted">{{ __('About 30 generated photos in albums that each show a feature, sample pages, groups, people for every role and a year of activity.') }}</span>
                    </span>
                </label>
            </div>
            <x-input-error :messages="$errors->get('content')" class="mt-2" />
        </fieldset>

        <div x-show="content === 'demo'" x-cloak class="rounded-og border border-danger/40 px-4 py-3 text-sm">
            <p class="font-medium">{{ __('Only for trying things out') }}</p>
            <p class="mt-1 text-muted">
                {{ __('The demo adds accounts with the password ":password", one of them an administrator. Do not add it to a gallery on the internet, or delete those accounts afterwards under Users.', ['password' => \Database\Seeders\DemoSeeder::PASSWORD]) }}
            </p>
            <p class="mt-1 text-muted">{{ __('Adding it takes up to a few minutes. Keep this page open.') }}</p>
        </div>

        <div class="flex items-center gap-3">
            <x-primary-button x-bind:disabled="busy">{{ __('Finish setup') }}</x-primary-button>
            <span x-show="busy" x-cloak class="text-sm text-muted" role="status">{{ __('Working…') }}</span>
        </div>
    </form>
</x-setup.layout>
