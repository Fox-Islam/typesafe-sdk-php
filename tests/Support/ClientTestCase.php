<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Support;

use Phox\TypeSafe\Client;
use PHPUnit\Framework\TestCase;

abstract class ClientTestCase extends TestCase
{
    protected FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Client::environmentVariables() as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        $this->transport = new FakeTransport();
    }

    protected function client(): Client
    {
        return Client::make('test-api-key')->transport($this->transport);
    }
}
