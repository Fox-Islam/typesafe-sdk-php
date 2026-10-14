<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Questions;

use Phox\TypeSafe\Assets\Image;

/**
 * A question answered with a box around what its instructions describe in the
 * request's image.
 *
 * ```php
 * $client->systemOne()
 *     ->header(TypeSafe::IMAGES_HEADER, 'true')
 *     ->model('jev-preview')
 *     ->state(Image::fromPath('photo.jpg'))
 *     ->ask('dog', Box::locate('The dog'))
 *     ->send();
 * ```
 *
 * Needs Jev 1.14 or newer, and images enabled with {@see \Phox\TypeSafe\TypeSafe::IMAGES_HEADER}.
 *
 * @see https://docs.typesafe.ai/images
 *
 * @phpstan-import-type Content from Question
 */
final class Box extends Question
{
    /**
     * @param Content $instructions What to locate.
     */
    public static function locate(string|array|Image|null $instructions = null): self
    {
        return (new self())->instructions($instructions);
    }

    public function type(): string
    {
        return 'box';
    }

    public function validate(string $name): void
    {
        // Instructions are optional, and a box question takes no criteria.
    }

    protected function payload(): array
    {
        return [];
    }
}
