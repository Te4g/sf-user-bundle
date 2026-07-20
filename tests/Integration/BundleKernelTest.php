<?php

declare(strict_types=1);

namespace Acme\Bundle\TemplateBundle\Tests\Integration;

use Acme\Bundle\TemplateBundle\Service\ExampleService;
use Acme\Bundle\TemplateBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\TestCase;

final class BundleKernelTest extends TestCase
{
    public function testConfiguredMessageIsInjectedIntoExampleService(): void
    {
        $kernel = new TestKernel([
            'message' => 'Configured from test kernel',
        ]);

        $kernel->boot();

        try {
            $service = $kernel->getContainer()->get('test.'.ExampleService::class);

            self::assertInstanceOf(ExampleService::class, $service);
            self::assertSame('Configured from test kernel', $service->message());
        } finally {
            $kernel->shutdown();
        }
    }
}
