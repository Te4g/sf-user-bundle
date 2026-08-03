<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Te4g\UserBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testDefaultMessageIsConfigured(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame('Symfony bundle template', $config['message']);
    }

    public function testMessageCanBeConfigured(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [
            [
                'message' => 'Configured message',
            ],
        ]);

        self::assertSame('Configured message', $config['message']);
    }
}
