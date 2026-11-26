<?php

namespace App\Support;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Laravel's database session handler without the IP address and the raw user
 * agent. Only a short browser label ("Firefox on macOS", UserAgentLabel) is
 * kept in `user_agent`, so people can recognise their own sessions; the IP
 * address column stays empty. Registered as the `database` session driver in
 * AppServiceProvider.
 */
class PrivateDatabaseSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function addRequestInformation(&$payload): static
    {
        if ($this->container?->bound('request')) {
            $payload['user_agent'] = UserAgentLabel::from($this->container->make('request')->userAgent());
        }

        return $this;
    }
}
