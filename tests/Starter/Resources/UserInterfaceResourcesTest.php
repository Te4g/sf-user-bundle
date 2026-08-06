<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Symfony\UX\TwigComponent\Twig\ComponentExtension;
use Te4g\Starter\Generation\ResourceCatalog;
use Te4g\Starter\Generation\StaticResourceValidator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class UserInterfaceResourcesTest extends TestCase
{
    private const array ICONS = [
        'circle-check',
        'eye',
        'eye-off',
        'key-round',
        'mail',
        'trash-2',
        'triangle-alert',
    ];

    public function testApplicationShellAndOnlyMinimalControllersAreGenerated(): void
    {
        $files = (new ResourceCatalog($this->applicationResources()))->files();

        foreach ([
            'src/Controller/HomeController.php',
            'src/Controller/DashboardController.php',
            'templates/base.html.twig',
            'templates/layout/public.html.twig',
            'templates/layout/auth.html.twig',
            'templates/layout/app.html.twig',
            'templates/home/index.html.twig',
            'templates/dashboard/index.html.twig',
        ] as $path) {
            self::assertArrayHasKey($path, $files);
        }

        self::assertSame([
            'assets/controllers/alert_dialog_controller.js',
            'assets/controllers/password_visibility_controller.js',
        ], $this->customControllerPaths($files));
    }

    public function testPagesUseApplicationRoutesLayoutsAndOneRestrainedDashboardCard(): void
    {
        $homeController = $this->resource('src/Controller/HomeController.php');
        self::assertStringContainsString("#[Route('/', name: 'app_home', methods: ['GET'])]", $homeController);
        self::assertStringContainsString("render('home/index.html.twig')", $homeController);

        $dashboardController = $this->resource('src/Controller/DashboardController.php');
        self::assertStringContainsString("#[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]", $dashboardController);
        self::assertStringContainsString("render('dashboard/index.html.twig')", $dashboardController);

        $base = $this->resource('templates/base.html.twig');
        self::assertSame(1, substr_count($base, "asset('styles/app.css')"));
        self::assertSame(1, substr_count($base, "importmap('app')"));

        self::assertStringContainsString("{% extends 'layout/public.html.twig' %}", $this->resource('templates/home/index.html.twig'));
        foreach (['registration/register.html.twig', 'security/login.html.twig', 'security/check_email.html.twig', 'security/login_link_check.html.twig', 'password/request.html.twig', 'password/reset.html.twig'] as $path) {
            self::assertStringContainsString("{% extends 'layout/auth.html.twig' %}", $this->resource('templates/'.$path), $path);
        }
        foreach (['dashboard/index.html.twig', 'account/index.html.twig', 'account/profile.html.twig', 'account/password.html.twig', 'account/delete.html.twig'] as $path) {
            self::assertStringContainsString("{% extends 'layout/app.html.twig' %}", $this->resource('templates/'.$path), $path);
        }

        $dashboard = $this->resource('templates/dashboard/index.html.twig');
        self::assertSame(1, substr_count($dashboard, '<twig:Card>'));
        self::assertStringNotContainsString('metric', strtolower($dashboard));
        self::assertStringNotContainsString('demo', strtolower($dashboard));

        foreach ($this->templateResources() as $path) {
            $source = $this->contents($path);
            self::assertStringNotContainsString('bundles/Te4gUserBundle', $source, $path);
            self::assertStringNotContainsString('Te4gUserBundle', $source, $path);
            self::assertStringNotContainsString("path('te4g_user_", $source, $path);
        }
    }

    public function testFinalComponentsAssetsTranslationsAndFormThemeAreGenerated(): void
    {
        $files = (new ResourceCatalog($this->applicationResources()))->files();
        $manifest = $this->manifest();

        foreach ($manifest['files'] as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['path']);
            self::assertArrayHasKey($entry['path'], $files, $entry['path']);
            $upstream = $this->contents(dirname(__DIR__, 3).'/resources/skeleton/'.$entry['path']);
            self::assertSame(rtrim($upstream), rtrim($this->contents($files[$entry['path']])), $entry['path']);
        }

        self::assertSame(self::ICONS, $manifest['icons']['names']);
        $iconPaths = array_values(array_filter(
            array_keys($files),
            static fn (string $path): bool => str_starts_with($path, 'assets/icons/lucide/'),
        ));
        $expectedIconPaths = array_map(static fn (string $icon): string => 'assets/icons/lucide/'.$icon.'.svg', self::ICONS);
        sort($expectedIconPaths);
        self::assertSame($expectedIconPaths, $iconPaths);

        $css = $this->resource('assets/styles/app.css');
        self::assertStringStartsWith("@import \"tailwindcss\";\n", $css);
        self::assertStringContainsString('@import "../vendor/shadcn/dist/tailwind.css";', $css);
        self::assertStringContainsString('@import "../vendor/tw-animate-css/dist/tw-animate.css";', $css);
        self::assertStringContainsString('--primary:', $css);

        $app = $this->resource('assets/app.js');
        self::assertStringContainsString("import '@hotwired/turbo';", $app);
        self::assertStringContainsString("register('password-visibility'", $app);
        self::assertStringContainsString("register('alert-dialog'", $app);

        $twig = Yaml::parseFile($this->applicationResources().'/config/packages/account_twig.yaml');
        self::assertSame(['form/account_theme.html.twig'], $twig['twig']['form_themes']);

        $notices = $this->contents(dirname(__DIR__, 3).'/THIRD_PARTY_NOTICES.md');
        self::assertStringContainsString('resources/application/templates/components/', $notices);
        self::assertStringContainsString('resources/application/assets/icons/lucide/', $notices);
    }

    public function testTemplatesMatchOptionalControllerContextUnderStrictVariables(): void
    {
        $checkEmail = $this->resource('templates/security/check_email.html.twig');
        self::assertStringContainsString('{% if resend_form is defined %}', $checkEmail);
        self::assertStringContainsString("path('app_login')", $checkEmail);

        $password = $this->resource('templates/account/password.html.twig');
        self::assertStringNotContainsString('password_change_failed', $password);
    }

    public function testTwigYamlTranslationSvgAndBrowserOnlyBehaviorPassStaticGates(): void
    {
        $validator = new StaticResourceValidator();
        $twig = $this->twigParser();
        $files = (new ResourceCatalog($this->applicationResources()))->files();

        foreach ($files as $path => $absolutePath) {
            if (str_ends_with($path, '.twig')) {
                $twig->parse($twig->tokenize(new Source($this->contents($absolutePath), $path)));
            } elseif (preg_match('/\.(?:php|ya?ml|json|svg)$/', $path)) {
                $validator->validate($absolutePath);
            }
        }

        $translation = $this->applicationResources().'/translations/messages.en.xlf';
        $document = new \DOMDocument();
        self::assertTrue($document->load($translation, LIBXML_NONET));

        foreach ($this->customControllerPaths($files) as $path) {
            $source = $this->contents($files[$path]);
            self::assertStringNotContainsString('fetch(', $source, $path);
            self::assertStringNotContainsString('XMLHttpRequest', $source, $path);
            self::assertStringNotContainsString('innerHTML', $source, $path);
            self::assertStringNotContainsString('insertAdjacentHTML', $source, $path);

            $syntax = new Process(['node', '--check', $files[$path]]);
            $syntax->run();
            self::assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
        }

        $password = $this->resource('assets/controllers/password_visibility_controller.js');
        self::assertStringContainsString("static targets = ['input', 'button', 'label']", $password);
        self::assertStringContainsString("setAttribute('aria-pressed'", $password);

        $dialog = $this->resource('assets/controllers/alert_dialog_controller.js');
        self::assertStringContainsString("static targets = ['trigger', 'dialog', 'form']", $dialog);
        self::assertStringContainsString('requestSubmit()', $dialog);
        self::assertStringContainsString('disconnect()', $dialog);
    }

    /** @param array<string, string> $files
     * @return list<string>
     */
    private function customControllerPaths(array $files): array
    {
        return array_values(array_filter(
            array_keys($files),
            static fn (string $path): bool => str_starts_with($path, 'assets/controllers/') && str_ends_with($path, '_controller.js'),
        ));
    }

    /** @return list<string> */
    private function templateResources(): array
    {
        $paths = glob($this->applicationResources().'/templates/**/*.twig', GLOB_BRACE);
        self::assertIsArray($paths);

        $topLevel = glob($this->applicationResources().'/templates/*.twig');
        self::assertIsArray($topLevel);
        $paths = [...$paths, ...$topLevel];
        sort($paths);

        return array_values(array_unique($paths));
    }

    private function resource(string $path): string
    {
        return $this->contents($this->applicationResources().'/'.$path);
    }

    private function twigParser(): Environment
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addExtension(new ComponentExtension());
        foreach (['asset', 'csrf_token', 'form_end', 'form_errors', 'form_rest', 'form_start', 'form_widget', 'html_cva', 'importmap', 'path'] as $function) {
            $twig->addFunction(new TwigFunction($function));
        }
        foreach (['html_attr_type', 'tailwind_merge', 'trans'] as $filter) {
            $twig->addFilter(new TwigFilter($filter));
        }

        return $twig;
    }

    private function contents(string $path): string
    {
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source, $path);

        return $source;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $manifest = json_decode($this->contents(dirname(__DIR__, 3).'/resources/shadcn/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}
