@can(\App\Enums\Permission::ManageSettings->value)
    <p class="og-muted og-sidebar-version">
        {{ __('Version :version', ['version' => \App\Support\AppVersion::current()]) }}
        @if ($update = \App\Support\UpdateCheck::available())
            <br>
            <a href="{{ \App\Filament\Clusters\Settings\Pages\UpdateSettings::getUrl() }}" class="og-link">{{ __('Update to :version', ['version' => $update['version']]) }}</a>
        @endif
    </p>
@endcan
