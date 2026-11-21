<x-filament-widgets::widget>
    <x-filament::section :heading="__('Welcome, :name', ['name' => $name])" :description="$onlyAdminAccess ? __('Your account can open the media library and the documentation. Ask an administrator if you need more.') : null">
        <nav class="og-quick-actions" aria-label="{{ __('Quick actions') }}">
            @foreach ($actions as $action)
                <x-filament::button tag="a" :href="$action['url']" :icon="$action['icon']" color="gray" size="sm">{{ $action['label'] }}</x-filament::button>
            @endforeach
        </nav>
    </x-filament::section>
</x-filament-widgets::widget>
