<?php

namespace App\Support;

/**
 * Links shown in the site header, shared by all header layouts so theme
 * authors only arrange them. Pages come from the theme's "header_pages".
 * The admin link is in the account menu (adminUrl()), not among these.
 */
class Navigation
{
    /** @return list<array{label: string, href: string, active: bool}> */
    public static function links(string $pagesOption = 'header_pages'): array
    {
        $user = auth()->user();

        $links = array_values(array_filter([
            $user || config('gallery.public_albums_enabled') ? ['label' => __('Albums'), 'href' => route('home'), 'active' => request()->routeIs('home', 'albums.*')] : null,
            $user ? ['label' => __('My albums'), 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard')] : null,
        ]));

        foreach (Theme::pages($pagesOption) as $page) {
            $links[] = ['label' => $page->title, 'href' => route('pages.show', $page), 'active' => request()->is('pages/'.$page->slug)];
        }

        return $links;
    }

    /** The admin area for the account menu (under "Profile"), if the visitor may open it. */
    public static function adminUrl(): ?string
    {
        return auth()->user()?->opensAdmin() ? url('/admin') : null;
    }

    /** The landing page carries its own log-in / sign-up buttons. */
    public static function onLanding(): bool
    {
        return auth()->guest() && ! config('gallery.public_albums_enabled') && request()->routeIs('home');
    }
}
