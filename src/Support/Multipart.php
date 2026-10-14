<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Support;

/**
 * A `multipart/form-data` body (RFC 7578), as the System One endpoint takes a
 * request with images: the JSON in a part named `request`, then one part per file.
 *
 * @internal
 */
final class Multipart
{
    private function __construct() {}

    /**
     * @param list<array{name: string, contentType: string, content: string}> $parts
     * @return array{contentType: string, body: string}
     */
    public static function encode(array $parts): array
    {
        // Random, so it is practically certain not to occur inside a file.
        $boundary = bin2hex(random_bytes(16));
        $body = '';

        foreach ($parts as ['name' => $name, 'contentType' => $contentType, 'content' => $content]) {
            $disposition = $name === 'request' ? "name=\"{$name}\"" : "name=\"{$name}\"; filename=\"{$name}\"";
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; {$disposition}\r\nContent-Type: {$contentType}\r\n\r\n{$content}\r\n";
        }

        return [
            'contentType' => "multipart/form-data; boundary={$boundary}",
            'body' => $body . "--{$boundary}--\r\n",
        ];
    }

    /**
     * The parts of a body {@see encode()} wrote, by name, or `null` when
     * `$contentType` is not multipart.
     *
     * @return array<string, string>|null
     */
    public static function decode(string $contentType, string $body): ?array
    {
        if (preg_match('/^multipart\/form-data;\s*boundary="?([^";]+)"?/i', $contentType, $match) !== 1) {
            return null;
        }

        $parts = [];
        foreach (explode("--{$match[1]}", $body) as $chunk) {
            if (! str_starts_with($chunk, "\r\n")) {
                continue;
            }

            [$head, $content] = explode("\r\n\r\n", substr($chunk, 2), 2) + [1 => ''];

            if (preg_match('/name="([^"]*)"/', $head, $name) === 1) {
                $parts[$name[1]] = substr($content, 0, -2);
            }
        }

        return $parts;
    }
}
