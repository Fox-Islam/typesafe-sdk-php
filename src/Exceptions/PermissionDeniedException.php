<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Exceptions;

/** HTTP 403: the API key may not access this resource. */
final class PermissionDeniedException extends ApiException {}
