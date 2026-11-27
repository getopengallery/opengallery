{{-- Shown to people who signed up while "New accounts need approval" is on, until an administrator approves them. --}}
<x-guest-layout>
    <x-slot:title>{{ __('Waiting for approval') }}</x-slot:title>

    <h1 class="text-heading font-semibold">{{ __('Your account is waiting for approval') }}</h1>
    <p class="mt-2 text-muted">
        {{ __('Thanks for signing up. An administrator of :site has to approve new accounts first. You will get an e-mail as soon as yours is ready.', ['site' => config('app.name')]) }}
    </p>

    <form method="POST" action="{{ route('logout') }}" class="mt-8">
        @csrf
        <button type="submit" class="og-btn-secondary">{{ __('Log out') }}</button>
    </form>
</x-guest-layout>
