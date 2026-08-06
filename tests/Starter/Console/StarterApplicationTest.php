<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Console;

use PHPUnit\Framework\TestCase;
use Te4g\Starter\Console\StarterApplication;

final class StarterApplicationTest extends TestCase
{
    public function testApplicationExposesInstallCommand(): void
    {
        $application = StarterApplication::create();

        self::assertTrue($application->has('install'));
        self::assertSame('te4g:starter', $application->getName());
    }
}
