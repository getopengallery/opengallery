<?php

namespace App\Extensions;

/**
 * Parsed theme.json / plugin.json.
 */
final readonly class Manifest
{
    /**
     * @param  array<string, mixed>  $data  raw manifest contents
     */
    public function __construct(
        public string $slug,
        public string $path,
        public string $name,
        public string $version,
        public ?string $description,
        public ?string $author,
        public array $data,
    ) {}

    public static function load(string $file): ?self
    {
        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data) || empty($data['name'])) {
            return null;
        }

        $path = dirname($file);

        return new self(
            slug: basename($path),
            path: $path,
            name: $data['name'],
            version: $data['version'] ?? '0.0.0',
            description: $data['description'] ?? null,
            author: $data['author'] ?? null,
            data: $data,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /** The name to show, translated if theme.json / plugin.json gives a translation key. */
    public function label(): string
    {
        return self::text($this->name);
    }

    /** The description to show, translated like label(). */
    public function summary(): ?string
    {
        return $this->description === null ? null : self::text($this->description);
    }

    /**
     * A user-facing text from a manifest. A namespaced translation key
     * ("theme-default::settings.palette", "my-plugin::plugin.name") is looked
     * up in the extension's own language files; any other text is shown as
     * written, never matched against core's translations.
     */
    public static function text(mixed $text): mixed
    {
        return is_string($text) && preg_match('/^[\w.-]+::[\w.-]+$/', $text) === 1 ? __($text) : $text;
    }
}
