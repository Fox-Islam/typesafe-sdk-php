<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Exceptions;

/** HTTP 401: the API key is missing or invalid. */
final class AuthenticationException extends ApiException {}
