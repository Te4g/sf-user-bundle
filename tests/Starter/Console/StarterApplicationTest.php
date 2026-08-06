<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Te4g\Starter\Console\StarterApplication;

final class StarterApplicationTest extends TestCase
{
    public function testApplicationExposesInstallCommand(): void
    {
        $application = StarterApplication::create();

        self::assertTrue($application->has('install'));
        self::assertSame('te4g:starter', $application->getName());
    }

    public function testInstallCommandReturnsSuccessAndExplainsItsPlaceholderState(): void
    {
        $application = StarterApplication::create();
        $application->setAutoExit(false);
        $applicationTester = new ApplicationTester($application);

        $exitCode = $applicationTester->run(['command' => 'install']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame("Starter installer pipeline is not configured yet.\n", $applicationTester->getDisplay());
    }
}
