<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class PersistenceResourcesTest extends TestCase
{
    public function testUserResourceIsApplicationOwnedAndSubscriberChecksVerifiedState(): void
    {
        $source = $this->resource('src/Entity/User.php');

        self::assertStringContainsString('namespace App\\Entity;', $source);
        self::assertStringContainsString('PasswordAuthenticatedUserInterface', $source);
        self::assertStringContainsString('UserInterface', $source);
        self::assertStringContainsString("\$roles[] = 'ROLE_USER';", $source);
        self::assertStringContainsString("#[ORM\\UniqueConstraint(name: 'UNIQ_USER_EMAIL', fields: ['email'])]", $source);
        self::assertStringContainsString("#[UniqueEntity(fields: ['email'])]", $source);
        self::assertStringNotContainsString('Te4g', $source);
        self::assertStringContainsString(
            'if (!$user instanceof User || $user->isVerified())',
            $this->resource('src/Security/VerifiedAccountSubscriber.php'),
        );
    }

    public function testUserAndRepositoryExposeDirectApplicationContracts(): void
    {
        $user = $this->resource('src/Entity/User.php');
        foreach (['name', 'email', 'pendingEmail', 'roles', 'password', 'verified'] as $property) {
            self::assertMatchesRegularExpression('/private [^;]+ \\$'.preg_quote($property, '/').'\\b/', $user);
        }

        foreach ([
            'setName(string $name): self',
            'setEmail(string $email): self',
            'setPendingEmail(?string $email): self',
            'setPassword(string $password): self',
            'setVerified(bool $verified): self',
        ] as $signature) {
            self::assertStringContainsString($signature, $user);
        }

        $repository = $this->resource('src/Repository/UserRepository.php');
        self::assertStringContainsString('findOneByNormalizedEmail(string $normalizedEmail): ?User', $repository);
        self::assertStringContainsString('save(User $user, bool $flush = true): void', $repository);
        self::assertStringContainsString('remove(User $user, bool $flush = true): void', $repository);
        self::assertStringNotContainsString('AccountUserInterface', $repository);
        self::assertStringNotContainsString('Te4g', $repository);
    }

    public function testSecurityUsesApplicationEntityProviderAndNativeLoginLinkControls(): void
    {
        $config = Yaml::parse($this->resource('config/packages/security.yaml'));
        $security = $config['security'];

        self::assertSame('App\\Entity\\User', $security['providers']['app_user_provider']['entity']['class']);
        self::assertSame('email', $security['providers']['app_user_provider']['entity']['property']);
        self::assertSame([
            'login_path' => 'app_login',
            'check_path' => 'app_login',
            'enable_csrf' => true,
            'default_target_path' => 'app_dashboard',
        ], $security['firewalls']['main']['form_login']);
        self::assertSame([
            'check_route' => 'app_login_link_check',
            'signature_properties' => ['id', 'email', 'password'],
            'lifetime' => 600,
            'max_uses' => 1,
            'check_post_only' => true,
            'used_link_cache' => 'cache.account_login_links',
            'success_handler' => 'App\\Security\\MagicLinkAuthenticationSuccessHandler',
        ], $security['firewalls']['main']['login_link']);
        self::assertSame([
            ['path' => '^/dashboard', 'roles' => 'ROLE_USER'],
            ['path' => '^/account', 'roles' => 'ROLE_USER'],
        ], $security['access_control']);
    }

    public function testUnverifiedSubscriberUsesTheExactRouteAllowlistAfterAuthentication(): void
    {
        $source = $this->resource('src/Security/VerifiedAccountSubscriber.php');

        foreach ([
            "'app_check_email'",
            "'app_verify_email'",
            "'app_verify_resend'",
            "'app_login_link_check'",
            "'app_logout'",
        ] as $route) {
            self::assertStringContainsString($route, $source);
        }

        self::assertStringContainsString("KernelEvents::REQUEST => ['onKernelRequest', -10]", $source);
        self::assertStringContainsString("generate('app_check_email')", $source);
        self::assertStringNotContainsString('ROLE_', $source);
    }

    public function testAccountInfrastructureHasNamedLimitersAndOneHourLifetimes(): void
    {
        $rateLimiters = Yaml::parse($this->resource('config/packages/account_rate_limiters.yaml'));

        self::assertArrayHasKey('cache.account_login_links', $rateLimiters['framework']['cache']['pools']);
        self::assertSame([
            'account_login',
            'account_registration',
            'account_magic_link',
            'account_password_reset',
            'account_verification_resend',
            'account_email_change',
        ], array_keys($rateLimiters['framework']['rate_limiter']));

        self::assertSame(
            ['lifetime' => 3600],
            Yaml::parse($this->resource('config/packages/verify_email.yaml'))['symfonycasts_verify_email'],
        );

        $reset = Yaml::parse($this->resource('config/packages/reset_password.yaml'));
        self::assertSame(3600, $reset['symfonycasts_reset_password']['lifetime']);
        self::assertSame(3600, $reset['symfonycasts_reset_password']['throttle_limit']);
        self::assertSame(
            'App\\Repository\\ResetPasswordRequestRepository',
            $reset['services']['SymfonyCasts\\Bundle\\ResetPassword\\Persistence\\ResetPasswordRequestRepositoryInterface']['alias'],
        );

        $doctrine = Yaml::parse($this->resource('config/packages/test/doctrine.yaml'));
        self::assertSame('sqlite:///%kernel.project_dir%/var/test.db', $doctrine['doctrine']['dbal']['url']);
    }

    public function testEveryPersistencePhpResourceLintsAndHasNoStarterRuntimeReference(): void
    {
        foreach ($this->phpResources() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringNotContainsString('Te4g', $source, $path);

            $lint = new Process([PHP_BINARY, '-l', $path]);
            $lint->run();

            self::assertTrue($lint->isSuccessful(), $lint->getErrorOutput().$lint->getOutput());
        }
    }

    private function resource(string $path): string
    {
        $source = file_get_contents($this->applicationResources().'/'.$path);
        self::assertIsString($source, $path);

        return $source;
    }

    /** @return list<string> */
    private function phpResources(): array
    {
        $paths = glob($this->applicationResources().'/{src/Entity,src/Repository,src/Account,src/Security}/*.php', GLOB_BRACE);
        self::assertIsArray($paths);
        sort($paths);

        return $paths;
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}
