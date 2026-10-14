<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

use Phox\TypeSafe\Exceptions\TypeSafeException;

/**
 * Where the subject of a box question is in the image. Each edge is a fraction
 * from zero to one: `top` and `bottom` of the image's height measured from the
 * top, `left` and `right` of its width measured from the left.
 *
 * @see \Phox\TypeSafe\Questions\Box
 */
final class BoxAnswer extends Answer
{
    public function type(): string
    {
        return 'box';
    }

    public function top(): float
    {
        return $this->edge('top');
    }

    public function bottom(): float
    {
        return $this->edge('bottom');
    }

    public function left(): float
    {
        return $this->edge('left');
    }

    public function right(): float
    {
        return $this->edge('right');
    }

    /**
     * @return array{top: float, bottom: float, left: float, right: float}
     */
    public function box(): array
    {
        return ['top' => $this->top(), 'bottom' => $this->bottom(), 'left' => $this->left(), 'right' => $this->right()];
    }

    /**
     * The box in pixels of an image `$width` by `$height`, as `[x, y, width, height]`
     * from the top-left corner.
     *
     * @return array{int, int, int, int}
     */
    public function inPixels(int $width, int $height): array
    {
        $x = (int) round($this->left() * $width);
        $y = (int) round($this->top() * $height);

        return [$x, $y, (int) round($this->right() * $width) - $x, (int) round($this->bottom() * $height) - $y];
    }

    /** Confidence in the box, from zero to one, or `null` when the API did not report it. */
    public function confidence(): ?float
    {
        $value = $this->data['confidence'] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private function edge(string $edge): float
    {
        $box = $this->data['box'] ?? null;
        $value = is_array($box) ? ($box[$edge] ?? null) : null;

        if (! is_int($value) && ! is_float($value)) {
            throw new TypeSafeException(sprintf('Expected a number for "box.%s" in a box answer.', $edge));
        }

        return (float) $value;
    }
}
