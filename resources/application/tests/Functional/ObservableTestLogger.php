<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Psr\Log\AbstractLogger;

final class ObservableTestLogger extends AbstractLogger
{
    private const int MAX_CONTEXT_DEPTH = 4;
    private const int MAX_CONTEXT_ITEMS = 32;
    private const int MAX_STRING_BYTES = 2048;

    /** @var list<string> */
    private static array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $normalizedContext = self::normalizeValue($context);
        $encodedContext = json_encode(
            $normalizedContext,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        self::$records[] = '['.self::stringValue($level).'] '.self::stringValue($message).' context='.$encodedContext;
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

    private static function stringValue(mixed $value): string
    {
        try {
            $string = match (true) {
                is_string($value), is_int($value), is_float($value), is_bool($value), null === $value => (string) $value,
                $value instanceof \Stringable => (string) $value,
                is_object($value) => '[object '.$value::class.']',
                is_resource($value) => '[resource '.get_resource_type($value).']',
                default => '['.get_debug_type($value).']',
            };
        } catch (\Throwable $throwable) {
            $string = '[unprintable '.get_debug_type($value).' threw '.$throwable::class.']';
        }

        if (strlen($string) <= self::MAX_STRING_BYTES) {
            return $string;
        }

        return substr($string, 0, self::MAX_STRING_BYTES).'[truncated]';
    }

    private static function normalizeValue(mixed $value, int $depth = 0): mixed
    {
        if (null === $value || is_bool($value) || is_int($value) || is_string($value)) {
            return is_string($value) ? self::stringValue($value) : $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : self::stringValue($value);
        }

        if ($value instanceof \Throwable) {
            return [
                'type' => $value::class,
                'message' => self::stringValue($value->getMessage()),
                'code' => $value->getCode(),
            ];
        }

        if ($value instanceof \DateTimeInterface) {
            return [
                'type' => $value::class,
                'value' => $value->format(\DateTimeInterface::RFC3339_EXTENDED),
            ];
        }

        if ($value instanceof \Stringable) {
            return [
                'type' => $value::class,
                'value' => self::stringValue($value),
            ];
        }

        if (is_resource($value)) {
            return ['resource' => get_resource_type($value)];
        }

        if (is_object($value)) {
            return ['object' => $value::class];
        }

        if (!is_array($value)) {
            return '['.get_debug_type($value).']';
        }

        if ($depth >= self::MAX_CONTEXT_DEPTH) {
            return ['[truncated]' => 'maximum depth reached'];
        }

        ksort($value, SORT_STRING);
        $normalized = [];
        foreach ($value as $key => $item) {
            if (count($normalized) >= self::MAX_CONTEXT_ITEMS) {
                $normalized['[truncated]'] = count($value) - self::MAX_CONTEXT_ITEMS.' context items omitted';

                break;
            }

            $normalized[self::stringValue($key)] = self::normalizeValue($item, $depth + 1);
        }

        return $normalized;
    }
}
