<?php

namespace App\View\Components;

use App\Extensions\ThemeManager;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-block name="photo-grid" :photos="$photos" />
 *
 * Renders a themeable building block. Which Blade view is used is decided
 * by the active theme (see ThemeManager::blockView), so themes can swap the
 * presentation of a block without touching the pages that use it.
 * All other attributes are passed through to the block view as variables.
 */
class Block extends Component
{
    public function __construct(public string $name) {}

    public function render(): \Closure
    {
        return function (array $data): View {
            // Blade escapes bound attribute strings (:message="..."); blocks escape what they
            // print themselves, so hand them the original text instead of escaping it twice.
            $props = collect($data['attributes']->getAttributes())
                ->mapWithKeys(fn ($value, $key) => [
                    str($key)->camel()->value() => is_string($value) ? htmlspecialchars_decode($value, ENT_QUOTES) : $value,
                ])
                ->all();

            return view(app(ThemeManager::class)->blockView($this->name), [
                ...$props,
                'slot' => $data['slot'],
            ]);
        };
    }
}
