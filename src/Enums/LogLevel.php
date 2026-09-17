<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Enums;

use Phox\TypeSafe\Exceptions\TypeSafeException;
use Psr\Log\LogLevel as Psr;

/**
 * Verbosity of the SDK's own logging, from most to least verbose.
 *
 * `Info` logs a summary per request attempt; `Debug` adds headers and bodies.
 */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warn';
    case Error = 'error';
    case Off = 'off';

    public const self DEFAULT = self::Warning;

    /**
     * Whether a message at `$level` is written when this level is configured.
     */
    public function allows(self $level): bool
    {
        return $level->rank() >= $this->rank();
    }

    /**
     * The matching PSR-3 level name, for handing a message to a logger.
     */
    public function toPsr(): string
    {
        return match ($this) {
            self::Debug => Psr::DEBUG,
            self::Info => Psr::INFO,
            self::Warning => Psr::WARNING,
            self::Error, self::Off => Psr::ERROR,
        };
    }

    /**
     * @throws TypeSafeException The value is not a known log level.
     */
    public static function parse(string $value, string $source): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new TypeSafeException(sprintf(
                'Invalid log level "%s" from %s. Expected one of: %s.',
                $value,
                $source,
                implode(', ', array_column(self::cases(), 'value')),
            ));
    }

    private function rank(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warning => 2,
            self::Error => 3,
            self::Off => 4,
        };
    }
}
