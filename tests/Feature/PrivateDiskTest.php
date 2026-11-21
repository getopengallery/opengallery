<?php

use Illuminate\Support\Facades\Storage;

/*
 * The private `local` disk (branding, avatars, theme images) keeps Laravel's
 * `serve` option on: Filament's file upload fields preview existing files
 * through short-lived signed links. Unsigned requests must stay refused.
 */
it('serves files on the private local disk only through signed links', function () {
    $disk = Storage::disk('local');
    $path = 'avatars/serve-test-'.uniqid().'.webp';
    $disk->put($path, 'image');

    try {
        $this->get('/storage/'.$path)->assertForbidden();
        $this->get($disk->temporaryUrl($path, now()->addMinutes(5)))->assertOk();
    } finally {
        $disk->delete($path);
    }
});
