<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * What someone may do in an album they were invited to work on, from lowest
 * to highest. A role applies to the album and everything below it; see
 * App\Support\AlbumCollaboration for how a person's role is worked out.
 */
enum CollaboratorRole: string implements HasDescription, HasLabel
{
    /** Sees the album, uploads photos, removes their own uploads. */
    case Contributor = 'contributor';

    /** Also creates sub-albums and removes any photo from the album. */
    case Organizer = 'organizer';

    /** Also edits the album's settings. */
    case Editor = 'editor';

    public function rank(): int
    {
        return match ($this) {
            self::Contributor => 1,
            self::Organizer => 2,
            self::Editor => 3,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    public static function fromRank(int $rank): ?self
    {
        foreach (self::cases() as $role) {
            if ($role->rank() === $rank) {
                return $role;
            }
        }

        return null;
    }

    /** The highest of the given roles; null when there is none. */
    public static function highest(?self ...$roles): ?self
    {
        $highest = null;

        foreach ($roles as $role) {
            if ($role !== null && ($highest === null || $role->rank() > $highest->rank())) {
                $highest = $role;
            }
        }

        return $highest;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Contributor => __('Contributor'),
            self::Organizer => __('Organizer'),
            self::Editor => __('Editor'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Contributor => __('Sees the album, uploads photos and removes their own uploads.'),
            self::Organizer => __('Also creates sub-albums and removes any photo from the album.'),
            self::Editor => __('Also edits the album settings. Editors can publish the album and, by turning on downloads, reach the original files.'),
        };
    }
}
