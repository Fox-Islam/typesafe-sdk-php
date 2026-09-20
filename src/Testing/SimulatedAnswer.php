<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Testing;

/**
 * Builds a well-formed answer for a question nobody scripted, so a faked call
 * always comes back in the shape the SDK expects.
 *
 * Nothing here is random: the simulator takes the first thing the question
 * offered — a yes, the first label, level zero — and puts
 * {@see SimulatedAnswer::CONFIDENCE} on it, spreading what is left evenly over
 * the rest. Run the same test twice and you get the same answers.
 */
final class SimulatedAnswer
{
    /** The probability the simulator puts on the outcome it picks. */
    public const float CONFIDENCE = 0.75;

    private function __construct() {}

    /**
     * An answer for one question as it was sent, or `null` for a question type
     * this SDK version does not model.
     *
     * @param array<string, mixed> $question
     * @return array<string, mixed>|null
     */
    public static function forQuestion(array $question): ?array
    {
        return match ($question['type'] ?? null) {
            'noul' => ['type' => 'noul', 'noul' => self::CONFIDENCE],
            'choice' => self::choice($question),
            'score' => self::score($question),
            default => null,
        };
    }

    /**
     * The labels a choice question offered, in the order they were sent.
     *
     * @param array<string, mixed>|null $question
     * @return list<string>
     */
    public static function labels(?array $question): array
    {
        $criteria = $question['criteria'] ?? null;

        if (! is_array($criteria)) {
            return [];
        }

        return array_values(array_map(static fn (int|string $label): string => (string) $label, array_keys($criteria)));
    }

    /**
     * The rubric a score question offered, indexed by score from zero.
     *
     * @param array<string, mixed>|null $question
     * @return list<mixed>
     */
    public static function levels(?array $question): array
    {
        $criteria = $question['criteria'] ?? null;

        return is_array($criteria) ? array_values($criteria) : [];
    }

    /**
     * Probabilities over `$keys`, with `$confidence` on `$chosen` and the rest
     * split evenly. Keys keep the order they were given, and the values sum to one.
     *
     * @param list<int|string> $keys
     * @return array<int|string, float>
     */
    public static function spread(array $keys, int|string $chosen, float $confidence = self::CONFIDENCE): array
    {
        if (! in_array($chosen, $keys, true)) {
            $keys[] = $chosen;
        }

        if (count($keys) === 1) {
            return [$chosen => 1.0];
        }

        $each = round((1.0 - $confidence) / (count($keys) - 1), 4);

        $probabilities = [];
        foreach ($keys as $key) {
            $probabilities[$key] = $each;
        }

        // The chosen label absorbs the rounding, so the distribution still sums to one.
        $probabilities[$chosen] = round(1.0 - $each * (count($keys) - 1), 4);

        return $probabilities;
    }

    /**
     * The expected value of a distribution over integer scores.
     *
     * @param array<int|string, float> $probabilities
     */
    public static function expected(array $probabilities): float
    {
        $score = 0.0;
        foreach ($probabilities as $level => $probability) {
            $score += ((int) $level) * $probability;
        }

        return round($score, 4);
    }

    /**
     * The rubric as the API sends it back, keyed by score.
     *
     * @param list<mixed> $levels
     * @return array<int, mixed>
     */
    public static function legend(array $levels): array
    {
        $legend = [];
        foreach ($levels as $score => $description) {
            $legend[(int) $score] = $description;
        }

        return $legend;
    }

    /**
     * @param array<string, mixed> $question
     * @return array<string, mixed>
     */
    private static function choice(array $question): array
    {
        $labels = self::labels($question);

        if ($labels === []) {
            return ['type' => 'choice', 'choice' => '', 'confidence' => 0.0, 'probabilities' => []];
        }

        $chosen = $labels[0];
        $probabilities = self::spread($labels, $chosen);

        return [
            'type' => 'choice',
            'choice' => $chosen,
            'confidence' => $probabilities[$chosen],
            'probabilities' => $probabilities,
        ];
    }

    /**
     * @param array<string, mixed> $question
     * @return array<string, mixed>
     */
    private static function score(array $question): array
    {
        $levels = self::levels($question);

        if ($levels === []) {
            return ['type' => 'score', 'score' => 0.0, 'confidence' => 0.0, 'legend' => [], 'probabilities' => []];
        }

        $probabilities = self::spread(range(0, count($levels) - 1), 0);

        return [
            'type' => 'score',
            'score' => self::expected($probabilities),
            'confidence' => $probabilities[0],
            'legend' => self::legend($levels),
            'probabilities' => $probabilities,
        ];
    }
}
