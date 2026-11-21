<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How the photos of an album are ordered, on the site and in the admin.
 */
enum PhotoOrder: string implements HasLabel
{
    case Manual = 'manual';
    case TakenAsc = 'taken_asc';
    case TakenDesc = 'taken_desc';
    case UploadedAsc = 'uploaded_asc';
    case UploadedDesc = 'uploaded_desc';
    case TitleAsc = 'title_asc';
    case TitleDesc = 'title_desc';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => __('Manual (drag to reorder)'),
            self::TakenAsc => __('Date taken, oldest first'),
            self::TakenDesc => __('Date taken, newest first'),
            self::UploadedAsc => __('Upload date, oldest first'),
            self::UploadedDesc => __('Upload date, newest first'),
            self::TitleAsc => __('Name, A to Z'),
            self::TitleDesc => __('Name, Z to A'),
        };
    }

    /** @return array{0: string, 1: 'asc'|'desc'} column (on photos or the pivot) and direction */
    public function orderBy(): array
    {
        return match ($this) {
            self::Manual => ['album_photo.sort_order', 'asc'],
            self::TakenAsc => ['photos.taken_at', 'asc'],
            self::TakenDesc => ['photos.taken_at', 'desc'],
            self::UploadedAsc => ['photos.created_at', 'asc'],
            self::UploadedDesc => ['photos.created_at', 'desc'],
            self::TitleAsc => ['photos.title', 'asc'],
            self::TitleDesc => ['photos.title', 'desc'],
        };
    }
}
