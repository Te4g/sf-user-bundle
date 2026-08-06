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
        self::$records[] = '['.self::stringValue($level).'] '
            .self::renderMessage($message, $context)
            ." context=\n".self::contextDump($context);
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

    /** @param array<string, mixed> $context */
    private static function renderMessage(string|\Stringable $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (null === $value || is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = self::stringValue($value);
            }
        }

        return strtr(self::stringValue($message), $replacements);
    }

    /** @param array<string, mixed> $context */
    private static function contextDump(array $context): string
    {
        try {
            return print_r($context, true);
        } catch (\Throwable $throwable) {
            return '[unprintable context threw '.$throwable::class.']';
        }
    }

    private static function stringValue(mixed $value): string
    {
        try {
            return match (true) {
                is_string($value), is_int($value), is_float($value), is_bool($value), null === $value => (string) $value,
                $value instanceof \Stringable => (string) $value,
                is_object($value) => '[object '.$value::class.']',
                is_resource($value) => '[resource '.get_resource_type($value).']',
                default => '['.get_debug_type($value).']',
            };
        } catch (\Throwable $throwable) {
            return '[unprintable '.get_debug_type($value).' threw '.$throwable::class.']';
        }
    }
}
