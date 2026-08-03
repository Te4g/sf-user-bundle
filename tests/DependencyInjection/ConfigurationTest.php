<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Te4g\UserBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsKeepRuntimeDisabledUntilInstallation(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertFalse($config['enabled']);
        self::assertSame('App\\Entity\\User', $config['user_class']);
        self::assertSame('App\\Repository\\UserRepository', $config['account_repository']);
        self::assertSame('app_home', $config['redirects']['login_success']);
        self::assertTrue($config['features']['account_deletion']);
    }

    public function testEmptyUserClassIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'enabled' => true,
            'user_class' => '',
        ]]);
    }

    public function testExplicitValuesOverrideTheDisabledDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'enabled' => true,
            'user_class' => 'Tests\\Fixtures\\AccountUser',
            'account_repository' => 'tests.account_repository',
            'mailer' => [
                'from_email' => 'accounts@example.test',
                'from_name' => 'Accounts',
            ],
            'redirects' => [
                'login_success' => 'dashboard',
            ],
            'templates' => [
                'login' => '@App/security/login.html.twig',
            ],
            'features' => [
                'account_deletion' => false,
            ],
        ]]);

        self::assertTrue($config['enabled']);
        self::assertSame('Tests\\Fixtures\\AccountUser', $config['user_class']);
        self::assertSame('tests.account_repository', $config['account_repository']);
        self::assertSame('accounts@example.test', $config['mailer']['from_email']);
        self::assertSame('Accounts', $config['mailer']['from_name']);
        self::assertSame('dashboard', $config['redirects']['login_success']);
        self::assertSame('@App/security/login.html.twig', $config['templates']['login']);
        self::assertFalse($config['features']['account_deletion']);
    }
}
