<?php

namespace App\Support;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Laravel's database session handler without the IP address and user agent.
 * OpenGallery never stores either; the `sessions` columns stay empty.
 * Registered as the `database` session driver in AppServiceProvider.
 */
class PrivateDatabaseSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function addRequestInformation(&$payload): static
    {
        return $this;
    }
}
