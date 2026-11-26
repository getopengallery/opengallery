<x-guest-layout>
    <x-slot:title>{{ $album->title }}</x-slot:title>

    <div class="mb-6 inline-flex h-11 w-11 items-center justify-center rounded-full bg-surface text-ink" aria-hidden="true">
        <svg viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="14" height="10" rx="1.5"/><path d="m3.5 5.5 6.5 5 6.5-5"/></svg>
    </div>

    <h1 class="text-heading font-semibold">{{ $album->title }}</h1>
    <p class="mt-2 text-muted">
        @if ($failed)
            {{ __('We could not send your code. Try again in a moment.') }}
        @else
            {{ __('We sent a 6-digit code to :email. Enter it to see the photos.', ['email' => $email]) }}
        @endif
    </p>

    <x-auth-session-status class="mt-6" :status="session('status')" />

    <form method="POST" action="{{ route('albums.guest.verify', [$album, $token]) }}" class="mt-8 space-y-5">
        @csrf
        {{-- Six boxes, one digit each; they fill the hidden "code" field. Without JavaScript the plain field is shown instead. --}}
        <div
            x-data="{
                digits: ['', '', '', '', '', ''],
                get code() { return this.digits.join('') },
                fill(index, value) {
                    const numbers = value.replace(/\D/g, '').split('');
                    if (numbers.length === 0) { this.digits[index] = ''; return; }
                    numbers.slice(0, 6 - index).forEach((digit, offset) => this.digits[index + offset] = digit);
                    const next = Math.min(index + numbers.length, 5);
                    this.$nextTick(() => {
                        this.$refs['digit' + next].focus();
                        if (this.code.length === 6) { this.$root.closest('form').requestSubmit(); }
                    });
                },
                back(index, event) {
                    if (this.digits[index] === '' && index > 0) {
                        event.preventDefault();
                        this.digits[index - 1] = '';
                        this.$refs['digit' + (index - 1)].focus();
                    }
                },
                move(index) { if (index >= 0 && index <= 5) { this.$refs['digit' + index].focus(); } },
            }"
        >
            <x-input-label for="digit0" :value="__('Code')" />

            <input id="code" type="text" name="code" x-bind:type="'hidden'" x-bind:value="code" class="og-input mt-1.5 w-full" inputmode="numeric" required autocomplete="one-time-code">

            <div x-cloak class="mt-1.5 flex justify-between gap-2" role="group" aria-label="{{ __('6-digit code') }}">
                @foreach (range(0, 5) as $index)
                    <input
                        id="digit{{ $index }}"
                        x-ref="digit{{ $index }}"
                        x-model="digits[{{ $index }}]"
                        x-on:input="fill({{ $index }}, $event.target.value)"
                        x-on:keydown.backspace="back({{ $index }}, $event)"
                        x-on:keydown.arrow-left.prevent="move({{ $index - 1 }})"
                        x-on:keydown.arrow-right.prevent="move({{ $index + 1 }})"
                        x-on:focus="$event.target.select()"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        @if ($index === 0) autocomplete="one-time-code" autofocus @else autocomplete="off" @endif
                        aria-label="{{ __('Digit :number of 6', ['number' => $index + 1]) }}"
                        class="og-input h-14 w-full min-w-0 p-0 text-center text-2xl font-semibold tabular-nums"
                    >
                @endforeach
            </div>

            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <x-primary-button class="w-full py-2.5">{{ __('Open album') }}</x-primary-button>
    </form>

    <form method="POST" action="{{ route('albums.guest.resend', [$album, $token]) }}" class="mt-6">
        @csrf
        <p class="text-sm text-muted">
            {{ trans_choice('The code works for :count minute.|The code works for :count minutes.', \App\Support\AlbumGuests::CODE_MINUTES) }}
            <button type="submit" class="og-link text-ink">{{ __('Send a new code') }}</button>
        </p>
    </form>
</x-guest-layout>
