<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who may see an album (and therefore every photo inside it).
 */
enum Visibility: string implements HasLabel
{
    /** Anyone, including guests; listed publicly. */
    case Public = 'public';

    /** Anyone holding the share link; never listed. */
    case Unlisted = 'unlisted';

    /** Any logged-in user. */
    case Registered = 'registered';

    /** Only users / groups the album is explicitly shared with. */
    case Restricted = 'restricted';

    /** Only the owner (and staff with "view any album"). */
    case Private = 'private';

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => __('Public'),
            self::Unlisted => __('Unlisted (link only)'),
            self::Registered => __('Registered users'),
            self::Restricted => __('Specific users & groups'),
            self::Private => __('Private (owner only)'),
        };
    }
}
