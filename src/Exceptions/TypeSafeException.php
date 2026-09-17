<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Exceptions;

use RuntimeException;

/**
 * Base class for every error the SDK raises, including configuration and
 * question validation failures.
 */
class TypeSafeException extends RuntimeException {}
