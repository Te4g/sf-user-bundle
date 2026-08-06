<?php

declare(strict_types=1);

namespace Te4g\Starter\Generation;

use Te4g\Starter\Exception\GenerationFailed;
use Te4g\Starter\Project\Conflict;

final readonly class GenerationPlan
{
    /** @var list<PlannedResource> */
    private array $writes;

    /** @var list<Conflict> */
    private array $conflicts;

    /**
     * @param list<PlannedResource> $writes
     * @param list<Conflict>        $conflicts
     */
    public function __construct(array $writes, array $conflicts)
    {
        usort($writes, static fn (PlannedResource $left, PlannedResource $right): int => $left->relativePath() <=> $right->relativePath());
        usort($conflicts, static fn (Conflict $left, Conflict $right): int => [
            $left->path() ?? '',
            $left->reason(),
        ] <=> [
            $right->path() ?? '',
            $right->reason(),
        ]);

        $this->writes = $writes;
        $this->conflicts = $conflicts;
    }

    /** @return list<PlannedResource> */
    public function writes(): array
    {
        return $this->writes;
    }

    /** @return list<Conflict> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    public function throwIfConflicted(): void
    {
        if ([] === $this->conflicts) {
            return;
        }

        throw GenerationFailed::conflicted(array_map(static fn (Conflict $conflict): string => $conflict->message(), $this->conflicts));
    }
}
