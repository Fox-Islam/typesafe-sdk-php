<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Support;

final class Runtime
{
    private static ?string $description = null;

    private function __construct() {}

    /**
     * PHP version and platform, sent as the `X-TypeSafe-Runtime` header.
     */
    public static function describe(): string
    {
        return self::$description ??= sprintf('php/%s (%s; %s)', PHP_VERSION, PHP_OS_FAMILY, php_uname('m'));
    }
}
