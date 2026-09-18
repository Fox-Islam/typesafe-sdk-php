<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Client;
use Phox\TypeSafe\Enums\Provider;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Support\Env;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProviderTest extends ClientTestCase
{
    /**
     * The decisions endpoint answers in the same shape as `/v1/systemone`, plus
     * a generation id, a provider name and a price.
     *
     * @return array<string, mixed>
     */
    private function openRouterPayload(): array
    {
        return [
            'model' => 'typesafe/jev-1.13-20260917',
            'answers' => ['billing' => ['type' => 'noul', 'noul' => 0.91]],
            'usage' => ['input_tokens' => 282, 'output_tokens' => 20, 'cost' => 0.000011844],
            'id' => 'gen-dec-1789727085-yiIpRRL4Jg8vfrucRYm0',
            'provider' => 'TypeSafe',
        ];
    }

    #[Test]
    public function it_defaults_to_calling_typesafe_directly(): void
    {
        $client = Client::make('key');

        self::assertSame(Provider::TypeSafe, $client->getProvider());
        self::assertSame('https://api.typesafe.ai', $client->getBaseUrl());
    }

    #[Test]
    public function open_router_moves_the_host_and_the_path(): void
    {
        $this->transport->queue($this->openRouterPayload());

        $client = Client::make('key')->transport($this->transport)->openRouter();
        $client->systemOne()->state('hi')->noul('billing', 'Is this about billing?')->send();

        self::assertSame('https://openrouter.ai', $client->getBaseUrl());
        self::assertSame(
            'https://openrouter.ai/api/alpha/decisions',
            (string) $this->transport->lastRequest()->getUri(),
        );
    }

    #[Test]
    public function the_request_body_is_identical_on_both_providers(): void
    {
        $this->transport->queue($this->openRouterPayload())->queue($this->openRouterPayload());

        $direct = Client::make('key')->transport($this->transport);
        $direct->systemOne()->state('hi')->noul('billing', 'Is this about billing?')->send();
        $sentDirect = $this->transport->lastBody();

        $routed = Client::make('key')->transport($this->transport)->openRouter();
        $routed->systemOne()->state('hi')->noul('billing', 'Is this about billing?')->send();

        self::assertSame($sentDirect, $this->transport->lastBody());
    }

    #[Test]
    public function it_reads_the_open_router_key_when_the_provider_is_open_router(): void
    {
        $_ENV[Env::OPENROUTER_API_KEY] = 'sk-or-test';

        $client = Client::make()->openRouter();

        self::assertTrue($client->hasApiKey());
    }

    #[Test]
    public function a_typesafe_key_does_not_leak_into_an_open_router_call(): void
    {
        $_ENV[Env::API_KEY] = 'ts-key';

        $client = Client::make()->openRouter();

        self::assertFalse($client->hasApiKey());
    }

    #[Test]
    public function a_key_set_in_code_survives_a_provider_switch(): void
    {
        $client = Client::make('pinned-key')->openRouter();

        self::assertTrue($client->hasApiKey());
    }

    #[Test]
    public function a_base_url_set_in_code_survives_a_provider_switch(): void
    {
        $client = Client::make('key', 'https://proxy.example.com')->openRouter();

        self::assertSame('https://proxy.example.com', $client->getBaseUrl());
    }

    #[Test]
    public function a_base_url_from_the_environment_survives_a_provider_switch(): void
    {
        $_ENV[Env::BASE_URL] = 'https://proxy.example.com';

        self::assertSame('https://proxy.example.com', Client::make('key')->openRouter()->getBaseUrl());
    }

    #[Test]
    public function the_provider_can_come_from_the_environment(): void
    {
        $_ENV[Env::PROVIDER] = 'openrouter';
        $_ENV[Env::OPENROUTER_API_KEY] = 'sk-or-test';

        $client = Client::make();

        self::assertSame(Provider::OpenRouter, $client->getProvider());
        self::assertSame('https://openrouter.ai', $client->getBaseUrl());
        self::assertTrue($client->hasApiKey());
    }

    #[Test]
    public function an_unknown_provider_is_rejected_by_name(): void
    {
        $_ENV[Env::PROVIDER] = 'anthropic';

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Unknown provider "anthropic"');

        Client::make('key');
    }

    #[Test]
    public function the_missing_key_message_names_the_variable_for_the_provider(): void
    {
        $client = Client::make()->openRouter()->transport($this->transport);

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage(Env::OPENROUTER_API_KEY);

        $client->systemOne()->state('hi')->noul('q', 'ok?')->send();
    }

    #[Test]
    public function it_reads_the_generation_id_and_the_price_back(): void
    {
        $this->transport->queue($this->openRouterPayload());

        $response = Client::make('key')->transport($this->transport)->openRouter()
            ->systemOne()->state('hi')->noul('billing', 'Is this about billing?')->send();

        self::assertSame('gen-dec-1789727085-yiIpRRL4Jg8vfrucRYm0', $response->requestId());
        self::assertSame('TypeSafe', $response->provider());
        self::assertSame(0.000011844, $response->usage()->cost());
        self::assertSame(282, $response->usage()->inputTokens());
        self::assertEqualsWithDelta(0.91, $response->noul('billing')->noul(), 0.0001);
    }

    #[Test]
    public function the_generation_id_header_is_preferred_over_the_body(): void
    {
        $this->transport->queue($this->openRouterPayload(), 200, ['x-generation-id' => 'gen-from-header']);

        $response = Client::make('key')->transport($this->transport)->openRouter()
            ->systemOne()->state('hi')->noul('billing', 'ok?')->send();

        self::assertSame('gen-from-header', $response->requestId());
    }

    #[Test]
    public function calling_typesafe_directly_reports_no_provider_and_no_cost(): void
    {
        $this->transport->queue([
            'model' => 'jev-latest',
            'answers' => ['billing' => ['type' => 'noul', 'noul' => 0.5]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ]);

        $response = $this->client()->systemOne()->state('hi')->noul('billing', 'ok?')->send();

        self::assertNull($response->provider());
        self::assertNull($response->usage()->cost());
    }

    #[Test]
    public function listing_models_is_refused_where_there_is_no_catalogue(): void
    {
        $client = Client::make('key')->transport($this->transport)->openRouter();

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('does not publish a model catalogue');

        $client->models()->list();
    }

    #[Test]
    public function switching_back_to_typesafe_restores_its_host(): void
    {
        $client = Client::make('key')->openRouter()->provider('typesafe');

        self::assertSame(Provider::TypeSafe, $client->getProvider());
        self::assertSame('https://api.typesafe.ai', $client->getBaseUrl());
    }
}
