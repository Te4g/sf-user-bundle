<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class MigrationGenerationFailed extends \RuntimeException
{
    public static function failed(): self
    {
        return new self('Migration generation failed.');
    }
}
