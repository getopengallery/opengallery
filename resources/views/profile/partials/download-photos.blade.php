{{-- Download of everything the user uploaded (ProfileController@downloadPhotos). --}}
<section>
    <header>
        <h2 class="text-title font-semibold text-ink">{{ __('Your photos') }}</h2>
        <p class="mt-1 text-sm text-muted">
            {{ trans_choice('You uploaded :count photo. Download it as a zip file, in a folder named after its album.|You uploaded :count photos. Download them as one zip file, in folders named after their albums.', $uploadedPhotos) }}
        </p>
    </header>

    <a href="{{ route('profile.photos.download') }}" class="og-btn-secondary mt-6" download>{{ __('Download my photos') }}</a>
</section>
