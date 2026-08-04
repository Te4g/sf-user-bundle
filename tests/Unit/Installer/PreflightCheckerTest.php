<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Installer\CssFragmentMerger;
use Te4g\UserBundle\Installer\ManifestStore;
use Te4g\UserBundle\Installer\PreflightChecker;
use Te4g\UserBundle\Installer\ProjectLayout;

final class PreflightCheckerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/te4g-user-preflight-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
        $this->createCompatibleProject();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testCompatibleProjectPassesWithoutChangingTheFilesystem(): void
    {
        $before = $this->inventory();

        $conflicts = $this->checker()->check(ProjectLayout::at($this->projectDir));

        self::assertSame([], $conflicts);
        self::assertSame($before, $this->inventory());
    }

    public function testItReportsEveryVersionAndBundleConflictInDeterministicOrder(): void
    {
        $bundles = file_get_contents($this->projectDir.'/config/bundles.php');
        self::assertIsString($bundles);
        $bundles = str_replace([
            "    Symfony\\UX\\Icons\\UXIconsBundle::class => ['all' => true],\n",
            "    Symfonycasts\\TailwindBundle\\SymfonycastsTailwindBundle::class => ['all' => true],\n",
        ], '', $bundles);
        file_put_contents($this->projectDir.'/config/bundles.php', $bundles);

        $conflicts = $this->checker('8.3.99', '8.0.99')->check(ProjectLayout::at($this->projectDir));

        self::assertSame([
            '|php-version-unsupported',
            '|symfony-version-unsupported',
            'config/bundles.php|missing-bundle-symfony-ux-icons-uxiconsbundle',
            'config/bundles.php|missing-bundle-symfonycasts-tailwindbundle-symfonycaststailwindbundle',
        ], $this->conflictKeys($conflicts));
    }

    public function testItAggregatesFirewallRouteClassComponentAndPathConflicts(): void
    {
        file_put_contents($this->projectDir.'/config/packages/security.yaml', <<<'YAML'
            security:
                firewalls:
                    main:
                        lazy: true
                        provider: app_user_provider
                        form_login: true
            YAML);
        file_put_contents($this->projectDir.'/config/routes/collision.yaml', <<<'YAML'
            te4g_user_login:
                path: /application-login
            te4g_user_profile:
                path: /application-profile
            YAML);
        $this->write('src/Entity/User.php', '<?php // application-owned');
        $this->write('templates/components/Button.html.twig', 'application component');
        $this->write('src/Controller/AccountController.php', '<?php // application controller');
        symlink(sys_get_temp_dir().'/te4g-user-preflight-missing-target', $this->projectDir.'/assets/controllers/password_visibility_controller.js');

        $conflicts = $this->checker()->check(ProjectLayout::at($this->projectDir));
        $keys = $this->conflictKeys($conflicts);

        self::assertSame($keys, $this->sorted($keys));
        self::assertContains('config/packages/security.yaml|main-firewall-authenticator-conflict', $keys);
        self::assertContains('config/packages/security.yaml|main-firewall-provider-conflict', $keys);
        self::assertContains('config/routes/collision.yaml|route-name-te4g-user-login-conflict', $keys);
        self::assertContains('config/routes/collision.yaml|route-name-te4g-user-profile-conflict', $keys);
        self::assertContains('src/Controller/AccountController.php|existing-account-controller', $keys);
        self::assertContains('src/Entity/User.php|existing-application-class', $keys);
        self::assertContains('templates/components/Button.html.twig|existing-component', $keys);
        self::assertContains('assets/controllers/password_visibility_controller.js|unsafe-target-path', $keys);
    }

    public function testItReportsInvalidCssImportsManifestAndRequiredProjectShapeTogether(): void
    {
        file_put_contents($this->projectDir.'/assets/styles/app.css', <<<'CSS'
            .already-started {}
            @import "tailwindcss";
            CSS);
        file_put_contents($this->projectDir.'/config/te4g_user.install.json', '{secret malformed manifest');
        unlink($this->projectDir.'/bin/console');
        (new Filesystem())->remove($this->projectDir.'/translations');

        $conflicts = $this->checker()->check(ProjectLayout::at($this->projectDir));
        $keys = $this->conflictKeys($conflicts);

        self::assertSame($keys, $this->sorted($keys));
        self::assertContains('assets/styles/app.css|invalid-css-fragment-target', $keys);
        self::assertContains('bin/console|required-file-missing', $keys);
        self::assertContains('config/te4g_user.install.json|invalid-manifest', $keys);
        self::assertContains('translations|required-directory-missing', $keys);
    }

    public function testAValidManifestOwnsGeneratedTargetsButAnEditedOwnedFileConflicts(): void
    {
        $this->write('src/Entity/User.php', 'generated user');
        $manifest = (new ManifestStore('0.1.0'))->create(
            ['src/Entity/User.php' => hash('sha256', 'generated user')],
            new \DateTimeImmutable('2026-08-03T12:00:00+00:00'),
        );
        $this->write(ManifestStore::RELATIVE_PATH, $manifest->toJson());

        self::assertSame([], $this->checker()->check(ProjectLayout::at($this->projectDir)));

        file_put_contents($this->projectDir.'/src/Entity/User.php', 'application edit');
        $keys = $this->conflictKeys($this->checker()->check(ProjectLayout::at($this->projectDir)));

        self::assertContains('src/Entity/User.php|modified-managed-file', $keys);
    }

    public function testRouteAttributesOutsideTheGeneratedControllersCannotShadowBundleRouteNames(): void
    {
        $this->write('src/Controller/DashboardController.php', <<<'PHP'
            <?php

            namespace App\Controller;

            use Symfony\Component\Routing\Attribute\Route;

            final class DashboardController
            {
                #[Route('/mine', name: 'te4g_user_login')]
                public function __invoke(): void
                {
                }
            }
            PHP);

        $keys = $this->conflictKeys($this->checker()->check(ProjectLayout::at($this->projectDir)));

        self::assertContains('src/Controller/DashboardController.php|route-name-te4g-user-login-conflict', $keys);
    }

    public function testStatelessOrScopedMainFirewallIsRejectedAsIncompatible(): void
    {
        file_put_contents($this->projectDir.'/config/packages/security.yaml', <<<'YAML'
            security:
                firewalls:
                    main:
                        lazy: true
                        stateless: true
                        pattern: ^/api
                        logout: true
            YAML);

        $keys = $this->conflictKeys($this->checker()->check(ProjectLayout::at($this->projectDir)));

        self::assertContains('config/packages/security.yaml|main-firewall-mode-conflict', $keys);
        self::assertContains('config/packages/security.yaml|main-firewall-authenticator-conflict', $keys);
    }

    private function checker(string $phpVersion = '8.4.10', string $symfonyVersion = '8.1.2'): PreflightChecker
    {
        return new PreflightChecker(
            new ManifestStore('0.1.0'),
            new CssFragmentMerger(),
            $phpVersion,
            $symfonyVersion,
        );
    }

    private function createCompatibleProject(): void
    {
        foreach ([
            'assets/controllers',
            'assets/styles',
            'bin',
            'config/packages',
            'config/routes',
            'migrations',
            'src/Controller',
            'src/Entity',
            'src/Repository',
            'templates',
            'translations',
        ] as $directory) {
            mkdir($this->projectDir.'/'.$directory, 0777, true);
        }

        $this->write('composer.json', <<<'JSON'
            {
                "autoload": {
                    "psr-4": {
                        "App\\": "src/"
                    }
                }
            }
            JSON);
        $this->write('bin/console', "<?php\n");
        $this->write('assets/app.js', "import './bootstrap.js';\n");
        $this->write('assets/controllers.json', "{\"controllers\": [], \"entrypoints\": []}\n");
        $this->write('assets/styles/app.css', ".application { display: block; }\n");
        $this->write('importmap.php', "<?php\n\nreturn ['app' => ['path' => './assets/app.js', 'entrypoint' => true]];\n");
        $this->write('config/bundles.php', <<<'PHP'
            <?php

            return [
                Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
                Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
                Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
                Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
                Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
                Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
                Symfony\UX\Turbo\TurboBundle::class => ['all' => true],
                Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
                Symfony\UX\Icons\UXIconsBundle::class => ['all' => true],
                Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle::class => ['all' => true],
                SymfonyCasts\Bundle\VerifyEmail\SymfonyCastsVerifyEmailBundle::class => ['all' => true],
                SymfonyCasts\Bundle\ResetPassword\SymfonyCastsResetPasswordBundle::class => ['all' => true],
                Te4g\UserBundle\Te4gUserBundle::class => ['all' => true],
            ];
            PHP);
        $this->write('config/packages/security.yaml', <<<'YAML'
            security:
                firewalls:
                    dev:
                        pattern: ^/(_(profiler|wdt)|css|images|js)/
                        security: false
                    main:
                        lazy: true
            YAML);
        $this->write('config/routes/framework.yaml', <<<'YAML'
            _preview_error:
                resource: '@FrameworkBundle/Resources/config/routing/errors.xml'
                prefix: /_error
            YAML);
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->projectDir.'/'.$relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * @param list<InstallerConflict> $conflicts
     *
     * @return list<string>
     */
    private function conflictKeys(array $conflicts): array
    {
        return array_map(
            static fn (InstallerConflict $conflict): string => ($conflict->relativePath() ?? '').'|'.$conflict->code(),
            $conflicts,
        );
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function sorted(array $keys): array
    {
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return array<string, string> */
    private function inventory(): array
    {
        $inventory = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $path => $info) {
            if (!$info->isFile()) {
                continue;
            }

            $relative = substr((string) $path, strlen($this->projectDir) + 1);
            $hash = hash_file('sha256', (string) $path);
            self::assertIsString($hash);
            $inventory[$relative] = $hash;
        }
        ksort($inventory, SORT_STRING);

        return $inventory;
    }
}
