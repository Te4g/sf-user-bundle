<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Psr\Log\AbstractLogger;

final class ObservableTestLogger extends AbstractLogger
{
    /** @var list<string> */
    private static array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $message = (string) $message;
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{'.$key.'}'] = match (true) {
                null === $value, is_scalar($value), $value instanceof \Stringable => (string) $value,
                $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339),
                is_object($value) => '[object '.$value::class.']',
                default => '['.gettype($value).']',
            };
        }

        self::$records[] = '['.(string) $level.'] '.strtr($message, $replacements);
    }

    public static function clear(): void
    {
        self::$records = [];
    }

    public static function count(): int
    {
        return count(self::$records);
    }

    public static function contents(): string
    {
        return implode("\n", self::$records);
    }
}
