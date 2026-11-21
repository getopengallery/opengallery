<?php

namespace App\Enums;

/**
 * The steps of the web setup wizard (App\Http\Controllers\SetupController),
 * in order. Each value is also the route name suffix (`setup.{value}`).
 */
enum SetupStep: string
{
    case Welcome = 'welcome';
    case Database = 'database';
    case Administrator = 'administrator';
    case Site = 'site';
    case Demo = 'demo';

    public function label(): string
    {
        return match ($this) {
            self::Welcome => __('Welcome'),
            self::Database => __('Database'),
            self::Administrator => __('Administrator'),
            self::Site => __('Your gallery'),
            self::Demo => __('Demo content'),
        };
    }

    public function route(): string
    {
        return 'setup.'.$this->value;
    }

    public function position(): int
    {
        return array_search($this, self::cases(), true);
    }
}
