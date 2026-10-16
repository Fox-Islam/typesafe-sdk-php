<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Assets;

use JsonSerializable;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Stringable;

/**
 * A PNG or JPEG image to send with a System One call.
 *
 * Put it anywhere text can go - the state, a question's instructions, a
 * criterion - or interpolate it into a string. Either way it is written as its
 * reference, `<image:…>`, and the file is uploaded with the request:
 *
 * ```php
 * $photo = Image::fromPath('returns/RMA-1042.jpg');
 *
 * $client->systemOne()
 *     ->state("The customer sent {$photo} with their return.")
 *     ->noul('damaged', 'Is the product in the photo damaged?')
 *     ->send();
 * ```
 *
 * The API reads the format from the bytes and rejects anything other than PNG
 * or JPEG. A request to TypeSafe with images needs the
 * {@see \Phox\TypeSafe\TypeSafe::IMAGES_HEADER} header. On OpenRouter the image
 * is written inline as a base64 data URI, not uploaded.
 *
 * @see https://docs.typesafe.ai/images
 */
final class Image implements JsonSerializable, Stringable
{
    /** Matches the reference an image is written as. */
    public const string REFERENCE_PATTERN = '/<image:[0-9a-f]{32}>/';

    /**
     * Every live image, by reference, so a reference interpolated into a
     * string resolves back to its file when the call is sent.
     *
     * @var array<string, \WeakReference<self>>
     */
    private static array $live = [];

    private string $reference;

    private function __construct(private readonly string $bytes)
    {
        if ($bytes === '') {
            throw new TypeSafeException('Image data is empty.');
        }

        $this->reference = '<image:' . bin2hex(random_bytes(16)) . '>';
        self::$live[$this->reference] = \WeakReference::create($this);
    }

    public function __clone()
    {
        // A clone sharing the reference would drop it from the registry when freed.
        $this->reference = '<image:' . bin2hex(random_bytes(16)) . '>';
        self::$live[$this->reference] = \WeakReference::create($this);
    }

    public function __destruct()
    {
        unset(self::$live[$this->reference]);
    }

    /**
     * @throws TypeSafeException The file cannot be read or is empty.
     */
    public static function fromPath(string $path): self
    {
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($bytes === false) {
            throw new TypeSafeException("Image file \"{$path}\" cannot be read.");
        }

        return new self($bytes);
    }

    /**
     * @param string $bytes The image file's contents.
     *
     * @throws TypeSafeException `$bytes` is empty.
     */
    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }

    /**
     * @param string $base64 The file's contents, base64-encoded, without a `data:` prefix.
     *
     * @throws TypeSafeException `$base64` is not valid base64 or decodes to nothing.
     */
    public static function fromBase64(string $base64): self
    {
        $bytes = base64_decode($base64, true);

        if ($bytes === false) {
            throw new TypeSafeException('Image data must be valid base64 without a data: prefix.');
        }

        return new self($bytes);
    }

    /**
     * The image a reference was written for, or `null` once that image has
     * been freed or when the reference was never issued.
     *
     * @internal
     */
    public static function find(string $reference): ?self
    {
        return (self::$live[$reference] ?? null)?->get();
    }

    /** The reference this image is written as wherever it is placed. */
    public function reference(): string
    {
        return $this->reference;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /** `image/png` or `image/jpeg` from the file signature, or `application/octet-stream`. */
    public function contentType(): string
    {
        return match (true) {
            str_starts_with($this->bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($this->bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    /** The image as a base64 `data:` URI, as OpenRouter takes it. */
    public function dataUri(): string
    {
        return 'data:' . $this->contentType() . ';base64,' . base64_encode($this->bytes);
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    public function __toString(): string
    {
        return $this->reference;
    }

    public function jsonSerialize(): string
    {
        return $this->reference;
    }

    /**
     * Keeps the file's bytes out of `var_dump()` and anything that logs through it.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['reference' => $this->reference, 'contentType' => $this->contentType(), 'size' => $this->size()];
    }
}
