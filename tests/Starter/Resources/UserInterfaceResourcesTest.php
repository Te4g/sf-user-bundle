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
    private const array RETIRED_VISIBLE_ENGLISH = [
        'Application · Welcome',
        'Dashboard · Application',
        'Account settings · Application',
        'Profile · Application',
        'Delete account · Application',
        'A thoughtful Symfony starting point',
        'Your account foundation is ready.',
        'Create an account or sign in to continue to the application.',
        'Open dashboard',
        'This is your authenticated application shell.',
        'Your workspace is ready',
        'There is nothing here yet.',
        'Open account settings',
        'Manage your identity, sign-in details, and account access.',
        'Account details',
        '>Name<',
        '>Email address<',
        '>Pending email<',
        '>Dashboard<',
        '>Account<',
        '>Sign in<',
        '>Create account<',
        '>Profile<',
        '>Password<',
        '>Delete account<',
        'Update your name or request an email change.',
        'Edit profile',
        'Choose a new password for your account.',
        'Permanently remove your account and its data.',
        'Permanently remove {{ account.email }} and its account data.',
        'A confirmation link is sent before your email address changes.',
        'Save profile',
        '>Cancel<',
        'Delete your account permanently?',
        'This action cannot be undone.',
        'Keep account',
        'Return to sign in',
        'Back home',
        '>Sign out<',
        'Primary navigation',
        'Account navigation',
    ];

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
            'src/Controller/LocaleController.php',
            'templates/base.html.twig',
            'templates/components/LanguageSwitcher.html.twig',
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
            self::assertFileIsReadable($files[$entry['path']], $entry['path']);
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

        $tailwindMerge = Yaml::parseFile($this->applicationResources().'/config/packages/account_tailwind_merge.yaml');
        self::assertSame(
            ['twig.extension'],
            $tailwindMerge['services']['TalesFromADev\\Twig\\Extra\\Tailwind\\TailwindExtension']['tags'],
        );
        self::assertSame(
            ['twig.runtime'],
            $tailwindMerge['services']['TalesFromADev\\Twig\\Extra\\Tailwind\\TailwindRuntime']['tags'],
        );

        $notices = $this->contents(dirname(__DIR__, 3).'/THIRD_PARTY_NOTICES.md');
        self::assertStringContainsString('resources/application/templates/components/', $notices);
        self::assertStringContainsString('resources/application/assets/icons/lucide/', $notices);
    }

    public function testGeneratedApplicationRetainsScopedLicenseAndThirdPartyNotices(): void
    {
        $files = (new ResourceCatalog($this->applicationResources()))->files();
        self::assertArrayHasKey('STARTER_LICENSES.md', $files);

        $notices = $this->resource('STARTER_LICENSES.md');
        self::assertStringContainsString('GPL-3.0-only', $notices);
        self::assertStringContainsString('files copied by `te4g/starter`', $notices);
        self::assertStringContainsString('independently authored application', $notices);
        self::assertStringContainsString('code that was not copied from the starter', $notices);
        self::assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $notices);
        self::assertStringContainsString('END OF TERMS AND CONDITIONS', $notices);
        self::assertStringContainsString('Copyright (c) 2025-present Fabien Potencier', $notices);
        self::assertStringContainsString('Copyright (c) 2026 Lucide Icons and Contributors', $notices);
        self::assertStringContainsString('Copyright (c) 2013-present Cole Bemis', $notices);
        self::assertStringContainsString('templates/components/', $notices);
        self::assertStringContainsString('assets/controllers/alert_dialog_controller.js', $notices);
        self::assertStringContainsString('assets/icons/lucide/', $notices);
        self::assertStringNotContainsString('resources/application/', $notices);
    }

    public function testTemplatesMatchOptionalControllerContextUnderStrictVariables(): void
    {
        $checkEmail = $this->resource('templates/security/check_email.html.twig');
        self::assertStringContainsString('{% if resend_form is defined %}', $checkEmail);
        self::assertStringContainsString("path('app_login')", $checkEmail);

        $password = $this->resource('templates/account/password.html.twig');
        self::assertStringNotContainsString('password_change_failed', $password);
    }

    public function testTemplatesDoNotContainRetiredVisibleEnglishCopy(): void
    {
        foreach ($this->templateResources() as $path) {
            $source = $this->contents($path);

            foreach (self::RETIRED_VISIBLE_ENGLISH as $retiredCopy) {
                self::assertStringNotContainsString($retiredCopy, $source, $path.' '.$retiredCopy);
            }
        }
    }

    public function testFinalFormsDeclareAccessibleLabelsAndUseTheirRenderedErrorIds(): void
    {
        foreach ([
            'RegistrationType.php' => ['form.name', 'form.email', 'form.password', 'form.password_confirmation'],
            'ProfileType.php' => ['form.name', 'form.email'],
            'PasswordChangeType.php' => ['form.current_password', 'form.new_password', 'form.password_confirmation'],
            'ResetPasswordType.php' => ['form.password', 'form.password_confirmation'],
            'DeleteAccountType.php' => ['form.current_password', 'form.delete_confirmation'],
        ] as $file => $labels) {
            $source = $this->resource('src/Form/'.$file);

            foreach ($labels as $label) {
                self::assertStringContainsString("'label' => '".$label."'", $source, $file.' '.$label);
            }
        }

        foreach (['LoginController.php', 'MagicLinkController.php', 'PasswordResetController.php', 'VerificationController.php'] as $file) {
            self::assertStringContainsString("'label' => 'form.email'", $this->resource('src/Controller/'.$file), $file);
        }

        foreach ([
            'templates/security/login.html.twig' => 'magic_link_form.email.vars.id',
            'templates/security/check_email.html.twig' => 'resend_form.email.vars.id',
            'templates/password/request.html.twig' => 'form.email.vars.id',
        ] as $path => $formIdExpression) {
            $template = $this->resource($path);
            self::assertStringContainsString($formIdExpression." ~ '_errors'", $template, $path);
            self::assertStringNotContainsString('email_email_errors', $template, $path);
        }
    }

    public function testRegistrationRendersNameBeforeEmailAndLoginLinksToPasswordRecovery(): void
    {
        $registration = $this->resource('templates/registration/register.html.twig');
        $namePosition = strpos($registration, 'form.name');
        $emailPosition = strpos($registration, 'form.email');
        $restPosition = strpos($registration, 'form_rest(form)');

        self::assertIsInt($namePosition);
        self::assertIsInt($emailPosition);
        self::assertIsInt($restPosition);
        self::assertLessThan($emailPosition, $namePosition);
        self::assertLessThan($restPosition, $emailPosition);

        self::assertStringContainsString("path('app_forgot_password_request')", $this->resource('templates/security/login.html.twig'));
    }

    public function testAlertDialogClosesBeforeNativeFormSubmission(): void
    {
        $test = dirname(__DIR__).'/Fixtures/alert-dialog-controller.test.mjs';
        $controller = $this->applicationResources().'/assets/controllers/alert_dialog_controller.js';
        $process = new Process(['node', $test, $controller]);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
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
