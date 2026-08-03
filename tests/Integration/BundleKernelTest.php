<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Tests\Fixtures\App\TestKernel;

final class BundleKernelTest extends TestCase
{
    public function testBundleBootsWithConfiguration(): void
    {
        $kernel = new TestKernel([
            'message' => 'Configured from test kernel',
        ]);

        $kernel->boot();

        try {
            self::assertTrue($kernel->getContainer()->hasParameter('template.message'));
            self::assertSame('Configured from test kernel', $kernel->getContainer()->getParameter('template.message'));
        } finally {
            $kernel->shutdown();
        }
    }
}
