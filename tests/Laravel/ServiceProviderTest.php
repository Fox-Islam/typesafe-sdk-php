<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Laravel;

use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Enums\LogLevel;
use Phox\TypeSafe\Enums\Provider;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Laravel\TypeSafeServiceProvider;
use PHPUnit\Framework\Attributes\Test;

final class ServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (['TYPESAFE_API_KEY', 'OPENROUTER_API_KEY', 'TYPESAFE_BASE_URL', 'TYPESAFE_PROVIDER', 'TYPESAFE_DEFAULT_MODEL'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        parent::setUp();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TypeSafeServiceProvider::class];
    }

    #[Test]
    public function it_registers_the_client_as_a_singleton(): void
    {
        $this->assertSame($this->client(), $this->client());
    }

    #[Test]
    public function it_ships_defaults_without_a_published_config(): void
    {
        $client = $this->client();

        $this->assertSame(Provider::TypeSafe, $client->getProvider());
        $this->assertSame('https://api.typesafe.ai', $client->getBaseUrl());
        $this->assertSame('jev-latest', $client->getDefaultModel());
    }

    #[Test]
    public function it_builds_the_client_from_config(): void
    {
        config([
            'typesafe.provider' => 'openrouter',
            'typesafe.keys.openrouter' => 'sk-or-configured',
            'typesafe.model' => 'typesafe/jev-1.13',
            'typesafe.timeout' => 3.5,
            'typesafe.log.level' => 'debug',
        ]);

        $client = $this->client();

        $this->assertSame(Provider::OpenRouter, $client->getProvider());
        $this->assertSame('https://openrouter.ai', $client->getBaseUrl());
        $this->assertSame('typesafe/jev-1.13', $client->getDefaultModel());
        $this->assertSame(3.5, $client->getTimeout());
        $this->assertSame(LogLevel::Debug, $client->getLogLevel());
        $this->assertTrue($client->hasApiKey());
    }

    #[Test]
    public function it_uses_the_key_belonging_to_the_configured_provider(): void
    {
        config([
            'typesafe.provider' => 'openrouter',
            'typesafe.keys.typesafe' => 'ts-key',
            'typesafe.keys.openrouter' => null,
        ]);

        $this->assertFalse($this->client()->hasApiKey());
    }

    #[Test]
    public function an_empty_config_value_falls_back_to_the_environment(): void
    {
        $_ENV['TYPESAFE_API_KEY'] = 'from-env';
        config(['typesafe.keys.typesafe' => '']);

        $this->assertTrue($this->client()->hasApiKey());
    }

    #[Test]
    public function a_configured_key_wins_over_the_environment(): void
    {
        $_ENV['TYPESAFE_API_KEY'] = 'from-env';
        config(['typesafe.keys.typesafe' => 'from-config']);

        $client = $this->client();

        $this->assertTrue($client->hasApiKey());
        $this->assertStringNotContainsString('from-config', (string) json_encode($client->__debugInfo()));
        $this->assertStringNotContainsString('from-env', (string) json_encode($client->__debugInfo()));
    }

    #[Test]
    public function logging_can_be_switched_off_entirely(): void
    {
        config(['typesafe.log.enabled' => false]);

        $this->assertSame(LogLevel::Off, $this->client()->getLogLevel());
    }

    #[Test]
    public function a_base_url_in_config_survives_the_provider(): void
    {
        config([
            'typesafe.provider' => 'openrouter',
            'typesafe.base_url' => 'https://proxy.example.com',
        ]);

        $this->assertSame('https://proxy.example.com', $this->client()->getBaseUrl());
    }

    #[Test]
    public function an_unknown_provider_in_config_is_rejected_by_name(): void
    {
        config(['typesafe.provider' => 'anthropic']);

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('config/typesafe.php');

        $this->client();
    }

    #[Test]
    public function it_still_builds_when_the_log_facade_is_mocked(): void
    {
        Log::shouldReceive('info')->andReturnNull();

        $this->assertInstanceOf(Client::class, $this->client());
    }

    #[Test]
    public function the_config_file_is_publishable(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'typesafe-config']);

        $this->assertFileExists($this->configFile());
        @unlink($this->configFile());
    }

    private function client(): Client
    {
        $client = $this->app?->make(Client::class);

        return $client instanceof Client ? $client : throw new \RuntimeException('Client not registered.');
    }

    private function configFile(): string
    {
        return (string) $this->app?->configPath('typesafe.php');
    }
}
