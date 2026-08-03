<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class InstallerRollbackIncomplete extends \RuntimeException
{
    private function __construct(
        private readonly string $primaryOutcome,
        private readonly string $rollbackOutcome,
    ) {
        parent::__construct('Installer rollback incomplete.');
    }

    public static function requested(string $rollbackOutcome): self
    {
        return new self('requested', self::safeRollbackOutcome($rollbackOutcome));
    }

    public static function after(string $primaryOutcome, string $rollbackOutcome): self
    {
        if (!in_array($primaryOutcome, ['conflict', 'validation-failed', 'write-failed'], true)) {
            $primaryOutcome = 'write-failed';
        }

        return new self($primaryOutcome, self::safeRollbackOutcome($rollbackOutcome));
    }

    public function primaryOutcome(): string
    {
        return $this->primaryOutcome;
    }

    public function rollbackOutcome(): string
    {
        return $this->rollbackOutcome;
    }

    private static function safeRollbackOutcome(string $rollbackOutcome): string
    {
        return in_array($rollbackOutcome, ['operation-failed', 'ownership-conflict'], true)
            ? $rollbackOutcome
            : 'operation-failed';
    }
}
