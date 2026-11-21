<?php

namespace App\Support;

use RuntimeException;

/** A photo was refused for a reason that may be shown to the person who uploaded it. */
class PhotoCannotBeProcessed extends RuntimeException {}
