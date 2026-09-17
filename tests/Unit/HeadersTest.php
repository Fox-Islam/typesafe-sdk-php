<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Support\Headers;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    #[Test]
    public function later_values_win_regardless_of_casing(): void
    {
        self::assertSame(
            ['content-type' => 'application/json'],
            Headers::merge(['Content-Type' => 'text/plain'], ['content-type' => 'application/json']),
        );
    }

    #[Test]
    public function a_null_value_removes_a_header(): void
    {
        self::assertSame([], Headers::merge(['X-Trace' => 'abc'], ['x-trace' => null]));
    }

    #[Test]
    public function lookups_ignore_casing(): void
    {
        self::assertSame('req_1', Headers::get(['X-TypeSafe-Request-Id' => 'req_1'], 'x-typesafe-request-id'));
        self::assertNull(Headers::get(['X-Other' => 'v'], 'x-typesafe-request-id'));
    }

    #[Test]
    public function repeated_psr7_values_are_joined(): void
    {
        self::assertSame(['Vary' => 'Accept, Origin'], Headers::flatten(['Vary' => ['Accept', 'Origin']]));
    }
}
