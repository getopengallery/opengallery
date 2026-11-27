<x-setup.layout :step="\App\Enums\SetupStep::Demo" :reached="\App\Enums\SetupStep::Demo" :finished="true" :title="__('Your gallery is ready')">
    <p class="mt-3 text-muted">
        {{ __('The setup is finished and this wizard is now closed. Log in with your administrator account (:email) to add albums and photos.', ['email' => $admin->email]) }}
    </p>

    <div class="mt-8 flex flex-wrap gap-3">
        <a href="{{ route('login') }}" class="og-btn-primary">{{ __('Log in') }}</a>
        <a href="{{ route('home') }}" class="og-btn-secondary">{{ __('View site') }}</a>
    </div>

    @if ($withDemo)
        <section class="mt-10">
            <h2 class="text-title font-semibold">{{ __('Demo accounts') }}</h2>
            <p class="mt-1 text-sm text-muted">
                {{ __('Every demo account uses the password ":password", for example mira@example.com (administrator), jonas@example.com (moderator), priya@example.com (uploader) and hana@example.com (user). The album "Product launch" has the password ":album".', ['password' => $demoPassword, 'album' => $albumPassword]) }}
            </p>
        </section>
    @endif

    <section class="mt-10">
        <h2 class="text-title font-semibold">{{ __('Still to do') }}</h2>
        <ul class="mt-3 list-disc space-y-2 ps-5 text-sm text-muted">
            @if ($mailDelivery === \App\Enums\MailDelivery::Server)
                <li>{{ __('Set up e-mail under Settings → E-mail and send a test e-mail, so password resets and invitations reach people.') }}</li>
            @else
                <li>{{ __('Send a test e-mail under Settings → E-mail to check the e-mail settings.') }}</li>
            @endif
            @if ($jobMode !== \App\Enums\BackgroundJobMode::Ajax)
                <li>{{ __('Set up the cron job or webcron address shown under System → Background jobs.') }}</li>
            @endif
            <li>{{ __('Choose a theme and add your logo under Themes and Settings.') }}</li>
            <li>{{ __('Back up the storage folder and the database regularly.') }}</li>
        </ul>
    </section>
</x-setup.layout>
