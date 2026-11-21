<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Photo;
use App\Support\ActivityLog;
use App\Support\AlbumAccess;
use App\Support\AlbumCollaboration;
use App\Support\AlbumPreviewImage;
use App\Support\AlbumSearch;
use App\Support\PageMeta;
use App\Support\PhotoFileResponse;
use App\Support\PhotoGridItems;
use App\Support\PhotoLocation;
use App\Support\PhotoZip;
use App\Support\SiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Spatie\MediaLibrary\Support\MediaStream;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AlbumController extends Controller
{
    /** Photos per page on an album's page (and per batch the lightbox loads). */
    public const PHOTOS_PER_PAGE = 60;

    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->user() && ! SiteSettings::publicAlbumsEnabled()) {
            return SiteSettings::guestHome() === 'login'
                ? redirect()->route('login')
                : view('landing');
        }

        $search = is_string($request->query('q')) ? trim($request->query('q')) : '';

        if ($search !== '') {
            return view('albums.index', ['albums' => AlbumSearch::run($request->user(), $search), 'search' => $search]);
        }

        $albums = Album::query()
            ->listableFor($request->user())
            ->whereNull('parent_id')
            ->with('coverPhoto.media')
            ->withCount('photos')
            ->orderBy('sort_order')
            ->latest()
            ->paginate(24);

        return view('albums.index', ['albums' => $albums, 'search' => '']);
    }

    /** Albums the user owns, and albums shared with them or that they work on as a collaborator. */
    public function mine(Request $request): View
    {
        $user = $request->user();
        $query = fn () => Album::query()->with('coverPhoto.media')->withCount('photos')->latest('updated_at');

        $own = $query()->whereBelongsTo($user, 'owner')->paginate(24, pageName: 'own')->withQueryString();
        $shared = $query()
            ->where(fn ($notOwn) => $notOwn->whereNull('owner_id')->orWhere('owner_id', '!=', $user->id))
            ->where(fn ($q) => $q
                ->whereHas('sharedUsers', fn ($u) => $u->whereKey($user->id))
                ->orWhereHas('sharedGroups.users', fn ($u) => $u->whereKey($user->id))
                ->orWhereIntegerInRaw('albums.id', AlbumCollaboration::albumIdsFor($user)))
            ->paginate(24, pageName: 'shared')->withQueryString();

        return view('albums.mine', compact('own', 'shared'));
    }

    public function show(Request $request, Album $album, ?string $shareToken = null): View|Response
    {
        if (AlbumAccess::requiresPassword($request->user(), $album)) {
            return response()->view('albums.password', ['album' => $album, 'meta' => PageMeta::protectedAlbum($album, $shareToken)], 401);
        }

        if (! AlbumAccess::canView($request->user(), $album)) {
            // Guests never learn that the album exists; members get a page that explains what to do.
            abort_unless($request->user(), 404);

            return response()->view('albums.forbidden', status: 403);
        }

        ActivityLog::albumView($album, $request);

        $album->load(['owner', 'parent']);

        $children = $album->children()->listableFor($request->user())->withCount('photos')->with('coverPhoto.media')
            ->paginate(24, pageName: 'albums')->withQueryString();
        $photos = $album->photos()->with('media')->paginate(self::PHOTOS_PER_PAGE)->withQueryString();

        $meta = PageMeta::album($album, $photos->total(), $shareToken);

        return view('albums.show', compact('album', 'children', 'photos', 'meta'));
    }

    /**
     * One page of the album's photos as lightbox items, so the lightbox can
     * go on to the neighbouring page without reloading. Same access as the
     * album's page; not logged as an album view (the visitor is already on it).
     */
    public function photos(Request $request, Album $album): JsonResponse
    {
        abort_unless(AlbumAccess::canView($request->user(), $album), 404);

        $photos = $album->photos()->with('media')->paginate(self::PHOTOS_PER_PAGE)
            ->withPath(route('albums.show', $album));

        return response()->json(['items' => PhotoGridItems::from($photos)->pluck('lightbox')]);
    }

    /**
     * All photos of the album as one zip, for visitors of albums that allow
     * downloads (and for people who may edit the album).
     */
    public function download(Request $request, Album $album): MediaStream|RedirectResponse
    {
        if (AlbumAccess::requiresPassword($request->user(), $album)) {
            return redirect()->route('albums.show', $album);
        }

        abort_unless(AlbumAccess::canView($request->user(), $album), 404);
        abort_unless($album->allow_downloads || $request->user()?->can('update', $album), 404);

        $photos = $album->photos()->with(['media', 'albums'])->get();
        abort_if($photos->isEmpty(), 404);

        return PhotoZip::make($album->title, $photos, withoutLocation: fn (Photo $photo) => PhotoLocation::isHiddenFrom($request->user(), $photo));
    }

    /** Password form submitted for a password-protected album. */
    public function unlock(Request $request, Album $album): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        abort_unless($album->hasPassword(), 404);

        if (! Hash::check($request->input('password'), $album->password)) {
            ActivityLog::albumPasswordFailed($album, $request);

            return back()->withErrors(['password' => __('That password is not right. Check it and try again.')]);
        }

        AlbumAccess::rememberPassword($album);

        return redirect()->route('albums.show', $album);
    }

    /**
     * Entry point for unlisted albums: /s/{token}. Unlocks the album for the
     * session and shows it at this address, so the link still works when
     * copied from the address bar, and link previews (fetched without
     * cookies) see the album's title and cover.
     */
    public function shared(Request $request, string $token): View|Response
    {
        $album = Album::where('share_token', $token)->firstOrFail();

        if ($album->accessExpired()) {
            return response()->view('albums.expired', compact('album'), 410);
        }

        AlbumAccess::unlock($album);

        return $this->show($request, $album, $token);
    }

    /**
     * Link preview image of an unlisted album's share link: the blurred cover
     * with a link badge, or with a lock if the album has a password
     * (AlbumPreviewImage). Apps that build previews fetch it without the
     * session the share link unlocked; holding the link is the key.
     */
    public function sharedCover(Request $request, string $token): SymfonyResponse
    {
        $album = Album::where('share_token', $token)->firstOrFail();

        abort_if($album->accessExpired(), 404);

        return $this->previewCard($request, $album, AlbumPreviewImage::badgeFor($album) ?? AlbumPreviewImage::LINK);
    }

    /**
     * Link preview image of a password-protected album that guests could
     * open with the password: the cover, blurred beyond recognition, with a
     * lock badge. Nothing for any other album.
     */
    public function protectedCover(Request $request, Album $album): SymfonyResponse
    {
        abort_unless($album->hasPassword() && $album->isVisibleTo(null), 404);

        return $this->previewCard($request, $album, AlbumPreviewImage::LOCK);
    }

    private function previewCard(Request $request, Album $album, string $badge): SymfonyResponse
    {
        $path = AlbumPreviewImage::path($album, $badge);
        abort_unless($path !== null, 404);

        return PhotoFileResponse::make($request, Storage::disk(AlbumPreviewImage::DISK), $path);
    }
}
