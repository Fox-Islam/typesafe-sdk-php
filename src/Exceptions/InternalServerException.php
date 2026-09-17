<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Exceptions;

/** HTTP 5xx: the server failed to handle the request. */
final class InternalServerException extends ApiException {}
