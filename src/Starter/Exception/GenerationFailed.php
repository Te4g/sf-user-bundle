<?php

declare(strict_types=1);

namespace Te4g\Starter\Exception;

final class GenerationFailed extends \RuntimeException
{
    /** @param list<string> $messages */
    public static function conflicted(array $messages): self
    {
        return new self(implode("\n", $messages));
    }

    public static function resourceCatalogUnavailable(): self
    {
        return new self('Generated application resources cannot be discovered.');
    }

    public static function unsafeResourcePath(): self
    {
        return new self('Generated application resources contain an unsafe path.');
    }

    public static function stagingUnavailable(): self
    {
        return new self('Unable to create a temporary directory for generated application resources.');
    }

    public static function unreadableResource(string $path): self
    {
        return new self(sprintf('Generated application resource cannot be read: %s', $path));
    }

    public static function cannotWrite(string $path): self
    {
        return new self(sprintf('Generated application resource cannot be written: %s', $path));
    }

    public static function invalidResource(string $path, \Throwable $previous): self
    {
        return new self(sprintf('Generated application resource is invalid: %s', $path), 0, $previous);
    }

    public static function plannedTargetChanged(string $path, string $operation): self
    {
        return new self(sprintf('%s: planned %s target changed', $path, $operation));
    }
}
