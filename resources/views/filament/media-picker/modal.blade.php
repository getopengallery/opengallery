{{--
    Body of PickMediaAction's modal: the media picker plus the glue that, when
    photos are chosen for this key, runs the opener's handler and closes the
    modal. Form fields (MediaPickerField) listen to the same event themselves.
--}}
<div
    x-data
    x-on:media-picked.window="
        if ($event.detail.key !== @js($key)) return;
        const items = $event.detail.items ?? [];
        const ids = items.map((item) => item.id);
        {{ $onPicked ?? '' }};
        $wire.unmountAction();
    "
>
    @livewire(\App\Livewire\MediaPicker::class, [
        'key' => $key,
        'multiple' => $multiple,
        'confirmLabel' => $confirmLabel,
        'scopeAlbumId' => $scopeAlbumId,
        'scope' => $scope,
        'uploadTo' => $uploadTo,
        'limitToAlbumId' => $limitToAlbumId ?? null,
    ], key('media-picker-'.$key))
</div>
