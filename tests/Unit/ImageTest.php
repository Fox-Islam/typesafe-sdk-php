<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Assets\Image;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Support\Multipart;
use Phox\TypeSafe\Testing\FakeTypeSafe;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ImageTest extends ClientTestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n" . 'not really the rest of a png';
    private const string JPEG = "\xFF\xD8\xFF" . 'not really the rest of a jpeg';

    /**
     * @return array<string, string>
     */
    private function sentParts(): array
    {
        $request = $this->transport->lastRequest();
        $parts = Multipart::decode($request->getHeaderLine('Content-Type'), (string) $request->getBody());
        self::assertNotNull($parts, 'The request was not sent as multipart.');

        return $parts;
    }

    #[Test]
    public function an_image_in_the_state_is_uploaded_as_a_part_and_written_as_its_reference(): void
    {
        $this->transport->queue(['model' => 'jev-latest', 'answers' => []]);
        $photo = Image::fromBytes(self::PNG);

        $this->client()->systemOne()->state($photo)->noul('damaged', 'Is it damaged?')->send();

        $body = $this->transport->lastBody();
        self::assertSame($photo->reference(), $body['state']);
        self::assertSame([$photo->reference() => ['type' => 'image', 'part' => 'asset-0']], $body['assets']);
        self::assertSame(self::PNG, $this->sentParts()['asset-0']);
        self::assertStringContainsString("Content-Type: image/png\r\n", (string) $this->transport->lastRequest()->getBody());
    }

    #[Test]
    public function an_image_interpolated_into_text_is_uploaded(): void
    {
        $this->transport->queue(['model' => 'jev-latest', 'answers' => []]);
        $upload = Image::fromBytes(self::JPEG);
        $catalog = Image::fromBytes(self::PNG);

        $this->client()->systemOne()
            ->state(['note' => "The seller uploaded {$upload}."])
            ->ask('same', Noul::ask("Is {$upload} the product in {$catalog}?"))
            ->send();

        $body = $this->transport->lastBody();
        self::assertSame([(string) $upload, (string) $catalog], array_keys($body['assets']));
        self::assertSame(self::JPEG, $this->sentParts()['asset-0']);
        self::assertSame(self::PNG, $this->sentParts()['asset-1']);
    }

    #[Test]
    public function an_image_used_twice_is_uploaded_once(): void
    {
        $this->transport->queue(['model' => 'jev-latest', 'answers' => []]);
        $photo = Image::fromBytes(self::PNG);

        $this->client()->systemOne()
            ->state($photo)
            ->ask('kind', Choice::ask($photo)->option('mug', $photo)->option('plate'))
            ->send();

        self::assertCount(1, $this->transport->lastBody()['assets']);
    }

    #[Test]
    public function a_named_asset_is_sent_under_its_name(): void
    {
        $this->transport->queue(['model' => 'jev-latest', 'answers' => []]);

        $this->client()->systemOne()
            ->state('A customer returned <delivered>.')
            ->noul('damaged', 'Is <delivered> damaged?')
            ->asset('<delivered>', Image::fromBytes(self::PNG))
            ->send();

        $body = $this->transport->lastBody();
        self::assertSame(['<delivered>' => ['type' => 'image', 'part' => 'asset-0']], $body['assets']);
        self::assertSame('A customer returned <delivered>.', $body['state']);
    }

    #[Test]
    public function a_named_asset_nothing_refers_to_is_rejected_before_sending(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('<unused>');

        try {
            $this->client()->systemOne()->state('No image here.')->noul('x')
                ->asset('<unused>', Image::fromBytes(self::PNG))
                ->send();
        } finally {
            self::assertSame(0, $this->transport->callCount());
        }
    }

    #[Test]
    public function a_reference_whose_image_was_freed_is_rejected_before_sending(): void
    {
        // The temporary Image is freed once the string is built.
        $state = 'Look at ' . Image::fromBytes(self::PNG);

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('no image behind them');

        $this->client()->systemOne()->state($state)->noul('x')->send();
    }

    #[Test]
    public function images_go_to_openrouter_as_inline_parts_where_they_are_placed(): void
    {
        $this->transport->queue(['model' => 'jev-latest', 'answers' => []]);
        $upload = Image::fromBytes(self::JPEG);
        $catalog = Image::fromBytes(self::PNG);
        $part = static fn (string $type, string $bytes): array => ['type' => 'image_url', 'image_url' => ['url' => "data:{$type};base64," . base64_encode($bytes)]];

        $this->client()->openRouter()->systemOne()
            ->state(['note' => 'Returned.', 'photo' => $upload])
            ->ask('same', Noul::ask("Is {$upload} the product in <catalog>?"))
            ->asset('<catalog>', $catalog)
            ->send();

        $body = $this->transport->lastBody();
        self::assertSame('application/json', $this->transport->lastRequest()->getHeaderLine('Content-Type'));
        self::assertArrayNotHasKey('assets', $body);
        self::assertSame(['note' => 'Returned.', 'photo' => $part('image/jpeg', self::JPEG)], $body['state']);
        self::assertSame(
            ['Is ', $part('image/jpeg', self::JPEG), ' the product in ', $part('image/png', self::PNG), '?'],
            $body['questions']['same']['instructions'],
        );
    }

    #[Test]
    public function the_fake_reports_images_sent_inline_in_order(): void
    {
        $fake = new FakeTypeSafe();
        $first = Image::fromBytes(self::JPEG);
        $second = Image::fromBytes(self::PNG);

        $fake->client()->openRouter()->systemOne()->state("{$first} then {$second}")->noul('x')->send();

        self::assertSame([self::JPEG, self::PNG], $fake->lastCall()->images());
    }

    #[Test]
    public function a_request_without_images_is_plain_json(): void
    {
        $this->transport->queue(['model' => 'jev-latest', 'answers' => []]);

        $this->client()->systemOne()->state('text')->noul('x')->send();

        self::assertSame('application/json', $this->transport->lastRequest()->getHeaderLine('Content-Type'));
        self::assertArrayNotHasKey('assets', $this->transport->lastBody());
    }

    #[Test]
    public function a_clone_gets_its_own_reference(): void
    {
        $photo = Image::fromBytes(self::PNG);
        $copy = clone $photo;
        unset($copy);

        self::assertNotNull(Image::find($photo->reference()));
    }

    #[Test]
    public function base64_must_be_plain_base64(): void
    {
        self::assertSame(self::PNG, Image::fromBase64(base64_encode(self::PNG))->bytes());

        $this->expectException(TypeSafeException::class);
        Image::fromBase64('data:image/png;base64,' . base64_encode(self::PNG));
    }

    #[Test]
    public function empty_data_is_rejected(): void
    {
        $this->expectException(TypeSafeException::class);
        Image::fromBytes('');
    }

    #[Test]
    public function the_content_type_comes_from_the_file_signature(): void
    {
        self::assertSame('image/png', Image::fromBytes(self::PNG)->contentType());
        self::assertSame('image/jpeg', Image::fromBytes(self::JPEG)->contentType());
        self::assertSame('application/octet-stream', Image::fromBytes('GIF89a')->contentType());
    }

    #[Test]
    public function dumping_an_image_leaves_out_its_bytes(): void
    {
        self::assertStringNotContainsString('not really', print_r(Image::fromBytes(self::PNG), true));
    }
}
