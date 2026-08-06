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
            $activeArrays = [];
            $normalized = self::normalizeArray($context, $activeArrays);
            $encoded = json_encode(
                $normalized,
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            return is_string($encoded) ? $encoded : '[unencodable context]';
        } catch (\Throwable $throwable) {
            return '[unprintable context threw '.$throwable::class.']';
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, true>     $activeArrays
     *
     * @return array<array-key, mixed>|string
     */
    private static function normalizeArray(array &$value, array &$activeArrays): array|string
    {
        $wrapper = [&$value];
        $reference = \ReflectionReference::fromArrayElement($wrapper, 0);
        if (!$reference instanceof \ReflectionReference) {
            return '[unreferenceable array]';
        }

        $referenceId = $reference->getId();
        if (isset($activeArrays[$referenceId])) {
            return '*RECURSION*';
        }

        $activeArrays[$referenceId] = true;
        $normalized = [];
        try {
            foreach ($value as $key => &$item) {
                $normalized[$key] = self::normalizeValue($item, $activeArrays);
            }
            unset($item);
        } finally {
            unset($activeArrays[$referenceId]);
        }

        return $normalized;
    }

    /** @param array<string, true> $activeArrays */
    private static function normalizeValue(mixed &$value, array &$activeArrays): mixed
    {
        if (is_array($value)) {
            return self::normalizeArray($value, $activeArrays);
        }

        if ($value instanceof \Throwable) {
            return self::normalizeThrowable($value);
        }

        if ($value instanceof \Stringable) {
            return [
                'type' => $value::class,
                'value' => self::stringValue($value),
            ];
        }

        if (is_object($value)) {
            return '[object '.$value::class.']';
        }

        if (is_resource($value)) {
            return '[resource '.get_resource_type($value).']';
        }

        if (is_float($value) && !is_finite($value)) {
            return self::stringValue($value);
        }

        return $value;
    }

    /** @return array{type: class-string<\Throwable>, message: string, code: int|string, previous?: array<string, mixed>} */
    private static function normalizeThrowable(\Throwable $throwable): array
    {
        $normalized = [
            'type' => $throwable::class,
            'message' => $throwable->getMessage(),
            'code' => $throwable->getCode(),
        ];

        if ($throwable->getPrevious() instanceof \Throwable) {
            $normalized['previous'] = self::normalizeThrowable($throwable->getPrevious());
        }

        return $normalized;
    }

    private static function stringValue(mixed $value): string
    {
        try {
            return match (true) {
                is_string($value), is_int($value), is_float($value), is_bool($value), null === $value => (string) $value,
                $value instanceof \Throwable => $value::class.': '.$value->getMessage(),
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
