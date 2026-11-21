<?php

namespace Database\Seeders;

use App\Extensions\ThemeManager;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Demo content pages (About, Imprint, Privacy, a members-only page and a
 * draft), linked from the default theme's header and footer. DemoSeeder
 * passes photos for the photo block on "About" and the gallery block on the
 * members-only page; run on its own the pages have text only:
 *
 *   php artisan db:seed --class=DemoPagesSeeder
 *
 * Safe to re-run: pages are matched by address, and theme links are only
 * set when none have been chosen yet. The texts are placeholders, not legal
 * advice.
 */
class DemoPagesSeeder extends Seeder
{
    /** @param list<int> $galleryIds */
    public function run(?int $photoId = null, array $galleryIds = []): void
    {
        $pages = collect($this->pages($photoId, $galleryIds))->mapWithKeys(fn (array $page) => [
            $page['slug'] => Page::updateOrCreate(['slug' => $page['slug']], $page),
        ]);

        $themes = app(ThemeManager::class);
        $options = $themes->options('default');

        if (empty($options['header_pages']) && empty($options['footer_pages'])) {
            $themes->saveOptions('default', [
                ...$options,
                'header_pages' => [$pages['about']->id],
                'footer_pages' => [$pages['imprint']->id, $pages['privacy']->id],
                'footer_text' => $options['footer_text'] ?? 'A demo of OpenGallery. Every photo on this site is generated.',
            ]);
        }
    }

    /**
     * @param  list<int>  $galleryIds
     * @return list<array{title: string, slug: string, body: string, is_published: bool, members_only: bool}>
     */
    private function pages(?int $photoId, array $galleryIds): array
    {
        $photo = $photoId ? self::block('photo', ['photo_id' => $photoId, 'caption' => 'Every photo in this demo is drawn by the demo seeder.', 'width' => 'wide']) : '';
        $gallery = $galleryIds !== [] ? self::block('gallery', ['photo_ids' => array_values($galleryIds), 'layout' => 'justified', 'columns' => 3, 'caption' => 'Golden hour, from the Landscapes album.', 'width' => 'wide']) : '';

        return [
            [
                'title' => 'About',
                'slug' => 'about',
                'is_published' => true,
                'members_only' => false,
                'body' => <<<HTML
                    <p>Welcome to the OpenGallery demo. This site shows what a self-hosted gallery can do: albums with sub-albums, sharing with people and groups, password-protected and expiring links, pages like this one, themes and an activity log.</p>
                    {$photo}
                    <h2>Look around</h2>
                    <ul>
                        <li><strong>Public albums</strong> such as Landscapes and City nights can be seen without an account.</li>
                        <li><strong>Log in</strong> to see the albums for registered users, the team and the people they are shared with.</li>
                        <li><strong>The admin</strong> shows the media library, users, groups, roles and a year of activity.</li>
                    </ul>
                    <h2>Accounts</h2>
                    <p>Every demo account uses the password <code>password</code>. Try <code>admin@example.com</code> for everything, or <code>priya@example.com</code> to see what an uploader can do.</p>
                    <blockquote>Photos are only worth keeping if somebody looks at them again.</blockquote>
                    HTML,
            ],
            [
                'title' => 'Imprint',
                'slug' => 'imprint',
                'is_published' => true,
                'members_only' => false,
                'body' => <<<'HTML'
                    <p><em>Example text. Replace it with the details required where you live.</em></p>
                    <h2>Responsible for this site</h2>
                    <p>OpenGallery Demo<br>Example Street 1<br>12345 Example City</p>
                    <h2>Contact</h2>
                    <p>E-mail: <a href="mailto:hello@example.org">hello@example.org</a></p>
                    <h2>Content</h2>
                    <p>This is a demo site. All photos are generated and free to use.</p>
                    HTML,
            ],
            [
                'title' => 'Privacy',
                'slug' => 'privacy',
                'is_published' => true,
                'members_only' => false,
                'body' => <<<'HTML'
                    <p><em>Example text. Replace it with a privacy policy that fits your site.</em></p>
                    <h2>What we store</h2>
                    <ul>
                        <li>Your name, e-mail address, profile picture and password (encrypted) when you have an account.</li>
                        <li>The photos and albums you upload.</li>
                        <li>When you log in and which albums you open, to show activity to the site's administrators. No IP addresses are stored; visitors without an account are counted anonymously per day.</li>
                    </ul>
                    <h2>What we do not do</h2>
                    <p>This site uses no advertising, no tracking services and no third-party fonts. Photos are never shared with anyone outside the albums they belong to.</p>
                    <h2>Your rights</h2>
                    <p>You can change or delete your account at any time on your profile page. For anything else, write to <a href="mailto:hello@example.org">hello@example.org</a>.</p>
                    HTML,
            ],
            [
                'title' => 'Photo walk guide',
                'slug' => 'photo-walk-guide',
                'is_published' => true,
                'members_only' => true,
                'body' => <<<HTML
                    <p>Only for logged-in members: how we plan the monthly photo walks.</p>
                    {$gallery}
                    <h2>Before you go</h2>
                    <ol>
                        <li>Check the sunset time and arrive an hour earlier.</li>
                        <li>Bring one lens. Moving your feet beats zooming.</li>
                        <li>Upload your favourites to the walk's album the same evening.</li>
                    </ol>
                    HTML,
            ],
            [
                'title' => 'Photo walk 2027 (draft)',
                'slug' => 'photo-walk-2027',
                'is_published' => false,
                'members_only' => false,
                'body' => '<p>Dates and meeting points coming soon.</p>',
            ],
        ];
    }

    /** A rich editor block (App\Filament\RichEditor\PhotoBlock / GalleryBlock). */
    private static function block(string $id, array $config): string
    {
        return '<div data-type="customBlock" data-id="'.$id.'" data-config="'.e(json_encode($config)).'"></div>';
    }
}
