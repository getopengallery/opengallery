<?php

namespace Plugins\IdentityProviders;

use RuntimeException;

/**
 * A sign-in that cannot continue. The message is shown to the person.
 */
class SignInException extends RuntimeException {}
