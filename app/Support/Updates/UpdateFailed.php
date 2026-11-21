<?php

namespace App\Support\Updates;

use RuntimeException;

/** A step of an update that cannot go on. The message is shown to the administrator. */
class UpdateFailed extends RuntimeException {}
