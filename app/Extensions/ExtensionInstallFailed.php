<?php

namespace App\Extensions;

use RuntimeException;

/**
 * An uploaded theme or plugin could not be installed. The message is
 * translated and meant for the person who uploaded it.
 */
class ExtensionInstallFailed extends RuntimeException {}
