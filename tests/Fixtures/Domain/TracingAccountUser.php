<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

final class TracingAccountUser extends TestAccountUser
{
    public function __construct(private readonly OperationTrace $trace)
    {
    }

    public function setVerified(bool $verified): void
    {
        $this->trace->record('set-verified');

        parent::setVerified($verified);
    }
}
