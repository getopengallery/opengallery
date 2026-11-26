<x-app-layout :meta="\App\Support\PageMeta::hidden()">
    <x-slot:title>{{ __('Profile') }}</x-slot:title>

    <x-slot name="header">
        <h1 class="text-heading font-semibold">{{ __('Profile') }}</h1>
    </x-slot>

    <div class="og-container">
        <div class="max-w-xl divide-y divide-line [&>*]:py-10 [&>*:first-child]:pt-0">
            <div>@include('profile.partials.update-avatar-form')</div>
            <div>@include('profile.partials.update-profile-information-form')</div>
            <div>@include('profile.partials.update-password-form')</div>
            @if (\App\Support\UserSessions::available())
                <div>@include('profile.partials.sessions')</div>
            @endif
            <div>@include('profile.partials.linked-accounts')</div>
            @if ($uploadedPhotos > 0)
                <div>@include('profile.partials.download-photos')</div>
            @endif
            <div>@include('profile.partials.delete-user-form')</div>
        </div>
    </div>
</x-app-layout>
