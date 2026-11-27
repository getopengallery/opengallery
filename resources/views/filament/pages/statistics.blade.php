@php
    use Illuminate\Support\Number;
    use App\Support\DateFormat;

    $visitNote = match (true) {
        ! $level->logsLogins() => __('The activity log does not record logins, album views or downloads (Settings → Activity log), so these figures stay empty.'),
        ! $level->logsVisits() => __('The activity log records logins but not album views or downloads (Settings → Activity log), so only logins are counted.'),
        default => null,
    };
    $retentionNote = $period->value > $retentionDays
        ? __('The activity log keeps entries for :days days, so older visits are no longer counted.', ['days' => $retentionDays])
        : null;
    $figure = fn (int $value) => Number::format($value);
    $bytes = fn (int $value) => Number::fileSize($value, precision: 1);
    $maxViews = max(1, (int) $topAlbums->max('views'));
    $maxDownloads = max(1, (int) $topDownloads->max('downloads'));
    $maxAlbumBytes = max(1, (int) collect($storageByAlbum)->max('bytes'));
    $maxUploaderBytes = max(1, (int) collect($storageByUploader)->max('bytes'));
    $added = ['photos' => array_sum(array_column($growth, 'photos')), 'albums' => array_sum(array_column($growth, 'albums')), 'members' => array_sum(array_column($growth, 'members'))];
@endphp

<x-filament-panels::page>
    <div class="og-stats-range">
        <x-filament::tabs :label="__('Period')">
            @foreach ($ranges as $range)
                <x-filament::tabs.item :active="$period === $range" wire:click="setRange({{ $range->value }})">{{ $range->getLabel() }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    </div>

    {{-- Visits --}}
    <x-filament::section :heading="__('Visits')" :description="__('Each visitor counts once per album, photo and day. Logins count every time.')">
        @foreach (array_filter([$visitNote, $retentionNote]) as $note)
            <p class="og-muted og-calendar-note">{{ $note }}</p>
        @endforeach

        <div class="og-trends">
            @include('filament.statistics.chart', [
                'buckets' => $series,
                'parts' => [['memberViews', 'is-members', __('Members')], ['guestViews', 'is-guests', __('Guests')]],
                'value' => $visits['views'],
                'label' => trans_choice('album view|album views', $visits['views']),
                'change' => $visits['views'] > 0 ? __(':percent% by guests', ['percent' => (int) round($visits['guestViews'] / $visits['views'] * 100)]) : null,
            ])
            @include('filament.statistics.chart', [
                'buckets' => $series,
                'parts' => [['downloads', 'is-downloads', __('Downloads')]],
                'value' => $visits['downloads'],
                'label' => trans_choice('download|downloads', $visits['downloads']),
            ])
            @include('filament.statistics.chart', [
                'buckets' => $series,
                'parts' => [['logins', 'is-members', __('Logins')]],
                'value' => $visits['logins'],
                'label' => trans_choice('login|logins', $visits['logins']),
            ])
        </div>
    </x-filament::section>

    <div class="og-stats-grid">
        {{-- Albums --}}
        <x-filament::section :heading="__('Most viewed albums')" :description="$period->getLabel()">
            @if ($topAlbums->isEmpty())
                <p class="og-muted">{{ __('No album was opened in this period.') }}</p>
            @else
                <ol class="og-list">
                    @foreach ($topAlbums as $row)
                        <li class="og-bar-row">
                            <span class="og-bar-label">
                                <a href="{{ $albumUrl($row->album) }}" class="og-link">{{ $row->album->title }}</a>
                                <span class="og-bar" style="--share: {{ round($row->views / $maxViews * 100) }}%" aria-hidden="true"></span>
                            </span>
                            <span class="og-num">
                                {{ trans_choice(':count view|:count views', $row->views) }}@if ($row->downloads), {{ trans_choice(':count zip download|:count zip downloads', $row->downloads) }}@endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('Albums nobody opened')" :description="trans_choice('{0} Every album was opened in this period.|{1} :count album older than this period was not opened in it.|[2,*] :count albums older than this period were not opened in it.', $unopened['count'])">
            @if ($level->logsVisits() && $unopened['albums']->isNotEmpty())
                <ol class="og-list">
                    @foreach ($unopened['albums'] as $album)
                        <li>
                            <a href="{{ $albumUrl($album) }}" class="og-link">{{ $album->title }}</a>
                            <span class="og-num og-muted">{{ trans_choice(':count photo|:count photos', $album->photos_count) }}</span>
                        </li>
                    @endforeach
                </ol>
            @elseif (! $level->logsVisits())
                <p class="og-muted">{{ __('Album views are not recorded, so this list cannot be made.') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('Most downloaded photos')" :description="$period->getLabel()">
            @if ($topDownloads->isEmpty())
                <p class="og-muted">{{ __('No original was downloaded in this period.') }}</p>
            @else
                <ol class="og-list">
                    @foreach ($topDownloads as $row)
                        <li class="og-bar-row">
                            <span class="og-bar-label">
                                <a href="{{ \App\Filament\Resources\Photos\PhotoResource::detailUrl($row->photo) }}" class="og-link">{{ $row->photo->title }}</a>
                                <span class="og-bar" style="--share: {{ round($row->downloads / $maxDownloads * 100) }}%" aria-hidden="true"></span>
                            </span>
                            <span class="og-num">{{ trans_choice(':count download|:count downloads', $row->downloads) }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-filament::section>
    </div>

    <div class="og-stats-grid">
        @include('filament.statistics.calendar', ['calendar' => $activityCalendar, 'mode' => $this->calendar, 'level' => $level])
    </div>

    <div class="og-stats-grid">
        {{-- Storage --}}
        <x-filament::section :heading="__('Disk space')" :description="__('All photos, including those in the trash.')">
            <div class="og-stats-figures">
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $bytes($storage['total']) }}</span><span class="og-stats-figure-label">{{ __('in total') }}</span></div>
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $bytes($storage['originals']) }}</span><span class="og-stats-figure-label">{{ __('originals') }}</span></div>
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $bytes($storage['generated']) }}</span><span class="og-stats-figure-label">{{ __('generated sizes') }}</span></div>
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $bytes($storage['saved']) }}</span><span class="og-stats-figure-label">{{ __('saved by compression') }}</span></div>
            </div>
            @if ($storage['pendingSizes'] > 0)
                <p class="og-note">{{ trans_choice('The generated sizes of :count photo are still being measured in the background.|The generated sizes of :count photos are still being measured in the background.', $storage['pendingSizes']) }}</p>
            @endif
            @if ($storage['unknownSavings'] > 0)
                <p class="og-note">{{ trans_choice(':count photo was compressed before sizes were recorded, so its savings are not included.|:count photos were compressed before sizes were recorded, so their savings are not included.', $storage['unknownSavings']) }}</p>
            @endif

            <h3 class="og-subheading">{{ __('Largest albums') }}</h3>
            <ol class="og-list">
                @forelse ($storageByAlbum as $row)
                    <li class="og-bar-row">
                        <span class="og-bar-label">
                            <span>{{ $row['title'] }}</span>
                            <span class="og-bar" style="--share: {{ round($row['bytes'] / $maxAlbumBytes * 100) }}%" aria-hidden="true"></span>
                        </span>
                        <span class="og-num">{{ $bytes($row['bytes']) }} · {{ trans_choice(':count photo|:count photos', $row['photos']) }}</span>
                    </li>
                @empty
                    <li class="og-muted">{{ __('No photos yet.') }}</li>
                @endforelse
            </ol>
            <p class="og-note">{{ __('A photo in several albums counts for each of them.') }}</p>

            <h3 class="og-subheading">{{ __('By uploader') }}</h3>
            <ol class="og-list">
                @forelse ($storageByUploader as $row)
                    <li class="og-bar-row">
                        <span class="og-bar-label">
                            <span>{{ $row['name'] ?? __('Deleted accounts') }}</span>
                            <span class="og-bar" style="--share: {{ round($row['bytes'] / $maxUploaderBytes * 100) }}%" aria-hidden="true"></span>
                        </span>
                        <span class="og-num">{{ $bytes($row['bytes']) }} · {{ trans_choice(':count photo|:count photos', $row['photos']) }}</span>
                    </li>
                @empty
                    <li class="og-muted">{{ __('No photos yet.') }}</li>
                @endforelse
            </ol>
        </x-filament::section>

        {{-- Growth --}}
        <x-filament::section :heading="__('Growth')" :description="$period->getLabel()">
            <div class="og-stats-figures">
                <div class="og-stats-figure"><span class="og-stats-figure-value">+{{ $figure($added['photos']) }}</span><span class="og-stats-figure-label">{{ trans_choice('photo|photos', $added['photos']) }}</span></div>
                <div class="og-stats-figure"><span class="og-stats-figure-value">+{{ $figure($added['albums']) }}</span><span class="og-stats-figure-label">{{ trans_choice('album|albums', $added['albums']) }}</span></div>
                <div class="og-stats-figure"><span class="og-stats-figure-value">+{{ $figure($added['members']) }}</span><span class="og-stats-figure-label">{{ trans_choice('member|members', $added['members']) }}</span></div>
            </div>

            <div class="og-trends" style="margin-top: 1.5rem">
                @include('filament.statistics.chart', [
                    'buckets' => $growth,
                    'parts' => [['totalPhotos', 'is-members', __('Photos')]],
                    'value' => end($growth)['totalPhotos'],
                    'label' => trans_choice('photo in total|photos in total', end($growth)['totalPhotos']),
                    'current' => false,
                ])
            </div>
        </x-filament::section>
    </div>

    <div class="og-stats-grid">
        {{-- Members --}}
        <x-filament::section :heading="__('Member activity')" :description="trans_choice('{1} :count member, by their last login.|[0,*] :count members, by their last login.', $members['members'])">
            <div class="og-stats-figures">
                @foreach ($members['active'] as $days => $count)
                    <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $figure($count) }}</span><span class="og-stats-figure-label">{{ __('active in the last :days days', ['days' => $days]) }}</span></div>
                @endforeach
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $figure($members['inactive']) }}</span><span class="og-stats-figure-label">{{ __('not seen for over :days days', ['days' => $inactiveDays]) }}</span></div>
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $figure($members['never']) }}</span><span class="og-stats-figure-label">{{ __('never logged in') }}</span></div>
            </div>

            @if ($leastActive->isNotEmpty())
                <h3 class="og-subheading">{{ __('Least active members') }}</h3>
                <ol class="og-list">
                    @foreach ($leastActive as $user)
                        <li>
                            @if ($url = $userUrl($user))
                                <a href="{{ $url }}" class="og-link">{{ $user->name }}</a>
                            @else
                                <span>{{ $user->name }}</span>
                            @endif
                            <span class="og-num og-muted">{{ $user->last_login_at ? __('Last login :date', ['date' => DateFormat::date($user->last_login_at)]) : __('Never logged in') }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-filament::section>

        {{-- Exposure --}}
        <x-filament::section :heading="__('Who can see what')" :description="trans_choice('{1} :count album, without personal system albums and the trash.|[0,*] :count albums, without personal system albums and the trash.', $exposure['albums'])">
            <div class="og-stats-figures">
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $figure($exposure['openPhotos']) }}</span><span class="og-stats-figure-label">{{ __('photos anyone can see without logging in') }}</span></div>
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $figure($exposure['openAlbums']) }}</span><span class="og-stats-figure-label">{{ __('albums open to everyone') }}</span></div>
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $figure($exposure['withPassword']) }}</span><span class="og-stats-figure-label">{{ __('albums with a password') }}</span></div>
                <div class="og-stats-figure"><span class="og-stats-figure-value">{{ $figure($exposure['guests']) }}</span><span class="og-stats-figure-label">{{ trans_choice('guest invitation in :albums album|guest invitations in :albums albums', $exposure['guests'], ['albums' => $exposure['guestAlbums']]) }}</span></div>
            </div>
            @if (! $exposure['publicAlbums'])
                <p class="og-note">{{ __('Public albums are turned off in the settings, so visitors who are not logged in see no albums.') }}</p>
            @elseif ($exposure['lockedOpenAlbums'] > 0)
                <p class="og-note">{{ trans_choice(':count public album has a password; its photos are not counted above.|:count public albums have a password; their photos are not counted above.', $exposure['lockedOpenAlbums']) }}</p>
            @endif
            @if ($exposure['expired'] > 0)
                <p class="og-note">{{ trans_choice('Access to :count album has expired.|Access to :count albums has expired.', $exposure['expired']) }}</p>
            @endif

            <h3 class="og-subheading">{{ __('Albums by visibility') }}</h3>
            <ol class="og-list">
                @foreach (\App\Enums\Visibility::cases() as $visibility)
                    <li class="og-bar-row">
                        <span class="og-bar-label">
                            <span>{{ $visibility->getLabel() }}</span>
                            <span class="og-bar" style="--share: {{ round(($exposure['byVisibility'][$visibility->value] ?? 0) / max(1, $exposure['albums']) * 100) }}%" aria-hidden="true"></span>
                        </span>
                        <span class="og-num">{{ $figure($exposure['byVisibility'][$visibility->value] ?? 0) }}</span>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    </div>
</x-filament-panels::page>
