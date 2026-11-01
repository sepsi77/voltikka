<?php

namespace App\Services\Fmi;

use RuntimeException;

/**
 * An FMI exception report or an unusable FMI response. The message never contains the request
 * URL, so it is safe to log.
 */
class FmiRequestException extends RuntimeException {}
