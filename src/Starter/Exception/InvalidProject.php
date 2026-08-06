<?php

declare(strict_types=1);

namespace Te4g\Starter\Exception;

final class InvalidProject extends \RuntimeException
{
    public static function projectRootNotFound(): self
    {
        return new self('Unable to locate a Symfony project root.');
    }

    public static function unsafePath(string $path): self
    {
        return new self('Unsafe project path.');
    }

    /** @param list<string> $messages */
    public static function incompatible(array $messages): self
    {
        return new self(implode("\n", $messages));
    }
}
