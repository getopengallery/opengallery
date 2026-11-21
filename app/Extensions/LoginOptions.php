<?php

namespace App\Extensions;

use Closure;
use Illuminate\Support\Collection;

/**
 * Registry of extra sign-in methods. Plugins register a resolver in their
 * service provider's boot():
 *
 *   app(LoginOptions::class)->resolveUsing(fn () => [new LoginOption(...), ...]);
 *
 * Resolvers run lazily (when the login page renders), so they may read
 * settings from the database.
 */
class LoginOptions
{
    /** @var list<Closure(): iterable<LoginOption>> */
    private array $resolvers = [];

    /** @var Collection<string, LoginOption>|null */
    private ?Collection $options = null;

    /** @param Closure(): iterable<LoginOption> $resolver */
    public function resolveUsing(Closure $resolver): void
    {
        $this->resolvers[] = $resolver;
        $this->options = null;
    }

    /** @return Collection<string, LoginOption> */
    public function all(): Collection
    {
        return $this->options ??= collect($this->resolvers)
            ->flatMap(fn (Closure $resolver) => $resolver())
            ->filter(fn ($option) => $option instanceof LoginOption)
            ->keyBy('key');
    }

    /** The option that "Log in" should go to directly, if any. */
    public function default(): ?LoginOption
    {
        return $this->all()->first(fn (LoginOption $option) => $option->isDefault);
    }

    public function find(string $key): ?LoginOption
    {
        return $this->all()->get($key);
    }
}
