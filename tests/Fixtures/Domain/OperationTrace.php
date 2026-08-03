<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

final class OperationTrace
{
    /** @var list<string> */
    private array $operations = [];

    public function record(string $operation): void
    {
        $this->operations[] = $operation;
    }

    /** @return list<string> */
    public function operations(): array
    {
        return $this->operations;
    }

    public function clear(): void
    {
        $this->operations = [];
    }
}
