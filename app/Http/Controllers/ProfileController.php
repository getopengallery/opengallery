<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Photo;
use App\Support\AccountDeletion;
use App\Support\Avatars;
use App\Support\PhotoZip;
use App\Support\SecurityNotices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Spatie\MediaLibrary\Support\MediaStream;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'ownedAlbums' => AccountDeletion::ownedAlbums([$request->user()->id]),
            'uploadedPhotos' => Photo::query()->where('uploader_id', $request->user()->id)->count(),
        ]);
    }

    /**
     * Every photo the user uploaded (not in the trash) as one zip, in a folder
     * per album, so they can keep their photos before deleting their account.
     */
    public function downloadPhotos(Request $request): MediaStream
    {
        $photos = Photo::query()
            ->where('uploader_id', $request->user()->id)
            ->with(['media', 'albums.parent'])
            ->oldest()
            ->get();

        abort_if($photos->isEmpty(), 404);

        return PhotoZip::make(__('My photos'), $photos, fn (Photo $photo) => ($album = $photo->activeAlbums()->first())
            ? PhotoZip::folderFor($album->path())
            : __('In no album'));
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->safe()->except('current_password'));
        $emailChanged = $request->user()->isDirty('email');
        $oldEmail = $request->user()->getOriginal('email');

        if ($emailChanged) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // A new address has to be confirmed again (only while e-mail works); the old one is told.
        if ($emailChanged) {
            $request->user()->sendEmailVerificationNotification();
            SecurityNotices::emailChanged($request->user(), $oldEmail);
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Replace the user's profile picture.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('avatar', [
            'avatar' => ['required', 'file', 'image', 'mimetypes:'.implode(',', Avatars::MIME_TYPES), 'max:'.Avatars::MAX_KB],
        ]);

        $request->user()->forceFill(['avatar_path' => Avatars::store($validated['avatar']->getRealPath())])->save();

        return Redirect::route('profile.edit')->with('status', 'avatar-updated');
    }

    /**
     * Remove the user's profile picture (initials are shown instead).
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['avatar_path' => null])->save();

        return Redirect::route('profile.edit')->with('status', 'avatar-removed');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $ownsAlbums = AccountDeletion::ownedAlbums([$user->id])->isNotEmpty();

        // People who own albums must agree that the albums are deleted too.
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
            'delete_albums' => $ownsAlbums ? ['accepted'] : [],
        ], [
            'delete_albums.accepted' => __('Your albums would be deleted too. Confirm this, or cancel and keep your account.'),
        ]);

        Auth::logout();

        AccountDeletion::deleteWithAllData($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
