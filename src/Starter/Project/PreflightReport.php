<?php

declare(strict_types=1);

namespace Te4g\Starter\Project;

use Te4g\Starter\Exception\InvalidProject;

final readonly class PreflightReport
{
    /** @var list<Conflict> */
    private array $conflicts;

    /** @param list<Conflict> $conflicts */
    public function __construct(array $conflicts)
    {
        usort($conflicts, static fn (Conflict $left, Conflict $right): int => [
            $left->path() ?? '',
            $left->reason(),
        ] <=> [
            $right->path() ?? '',
            $right->reason(),
        ]);

        $this->conflicts = $conflicts;
    }

    public function isCompatible(): bool
    {
        return [] === $this->conflicts;
    }

    /** @return list<Conflict> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    public function throwIfIncompatible(): void
    {
        if ($this->isCompatible()) {
            return;
        }

        throw InvalidProject::incompatible(array_map(static fn (Conflict $conflict): string => $conflict->message(), $this->conflicts));
    }
}
