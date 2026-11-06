<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An employee import cannot be completed; the message is meant for the user.
 */
class ImportException extends RuntimeException {}
