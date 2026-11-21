<?php

namespace App\Support;

use Closure;

/**
 * Values worked out once per request (or queue job) and then reused, such as
 * the albums shared with a person or a trash count. Bound as a scoped
 * instance (AppServiceProvider), so it starts empty for every request, every
 * queued job and every test. Unlike the cache it is never shared between
 * requests, which makes it safe for access decisions; whoever changes what a
 * value depends on calls forget().
 */
class RequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    public static function instance(): self
    {
        return app(self::class);
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    public function remember(string $key, Closure $compute): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $compute();
        }

        return $this->values[$key];
    }

    /** Counts up from 0 for each request: the first call returns 0, the next 1, ... */
    public function next(string $key): int
    {
        return $this->values[$key] = ($this->values[$key] ?? -1) + 1;
    }

    /** Forgets every value whose key starts with $prefix. */
    public function forget(string $prefix): void
    {
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
            }
        }
    }
}
