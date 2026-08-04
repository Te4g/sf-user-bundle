<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Te4g\UserBundle\Installer\ProjectLayout;
use Te4g\UserBundle\Installer\SkeletonRenderer;

final class ShadcnSkeletonTest extends TestCase
{
    private const array ICONS = ['circle-check', 'eye', 'eye-off', 'key-round', 'mail', 'trash-2', 'triangle-alert'];

    private string $projectDirectory;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir().'/te4g-shadcn-skeleton-'.bin2hex(random_bytes(8));
        mkdir($this->projectDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDirectory);
    }

    public function testManifestPinsSafePatchedUpstreamsAndAllHashesMatch(): void
    {
        $manifest = $this->manifest();

        self::assertSame('symfony/ux-toolkit', $manifest['upstream']['package']);
        self::assertTrue(version_compare($manifest['upstream']['version'], '3.3.0', '>='));
        self::assertSame('MIT', $manifest['upstream']['license']);
        self::assertSame('@iconify-json/lucide', $manifest['icons']['package']);
        self::assertSame('1.2.121', $manifest['icons']['version']);
        self::assertSame('ISC', $manifest['icons']['license']);

        foreach ($manifest['files'] as $file) {
            self::assertIsString($file['path']);
            self::assertStringNotContainsString('..', $file['path']);
            self::assertFalse(str_starts_with($file['path'], '/'));
            $path = dirname(__DIR__, 2).'/resources/skeleton/'.$file['path'];
            self::assertFileExists($path);
            self::assertSame($file['sha256'], hash_file('sha256', $path), $file['path']);
        }
    }

    public function testRendererInstallsEveryUiOverrideAndApplicationAsset(): void
    {
        $rendered = (new SkeletonRenderer())->renderAll(ProjectLayout::at($this->projectDirectory));

        foreach ([
            'templates/bundles/Te4gUserBundle/layout.html.twig',
            'templates/bundles/Te4gUserBundle/registration/register.html.twig',
            'templates/bundles/Te4gUserBundle/security/login.html.twig',
            'templates/bundles/Te4gUserBundle/security/login_link_check.html.twig',
            'templates/bundles/Te4gUserBundle/security/check_email.html.twig',
            'templates/bundles/Te4gUserBundle/password/request.html.twig',
            'templates/bundles/Te4gUserBundle/password/reset.html.twig',
            'templates/bundles/Te4gUserBundle/account/index.html.twig',
            'templates/bundles/Te4gUserBundle/account/profile.html.twig',
            'templates/bundles/Te4gUserBundle/account/password.html.twig',
            'templates/bundles/Te4gUserBundle/email/verify.html.twig',
            'templates/bundles/Te4gUserBundle/email/magic_link.html.twig',
            'templates/bundles/Te4gUserBundle/email/password_reset.html.twig',
            'templates/bundles/Te4gUserBundle/email/email_change.html.twig',
            'templates/form/te4g_user_shadcn_theme.html.twig',
            'assets/controllers/password_visibility_controller.js',
            'assets/controllers/alert_dialog_controller.js',
            'assets/styles/te4g_user.css.fragment',
            'importmap.required.json',
            'translations/Te4gUserBundle.en.xlf',
        ] as $path) {
            self::assertArrayHasKey($path, $rendered);
        }

        foreach (self::ICONS as $icon) {
            self::assertArrayHasKey('assets/icons/lucide/'.$icon.'.svg', $rendered);
        }

        self::assertStringContainsString('<twig:Card>', $rendered['templates/bundles/Te4gUserBundle/security/login.html.twig']);
        self::assertStringContainsString('<twig:Button', $rendered['templates/bundles/Te4gUserBundle/security/login.html.twig']);
        self::assertStringContainsString('aria-live="polite"', $rendered['templates/bundles/Te4gUserBundle/security/login.html.twig']);
    }

    public function testEveryHtmlPageUsesTheSingleApplicationOwnedAssetLayout(): void
    {
        $rendered = (new SkeletonRenderer())->renderAll(ProjectLayout::at($this->projectDirectory));
        $layout = $rendered['templates/bundles/Te4gUserBundle/layout.html.twig'];

        self::assertSame(1, substr_count($layout, "asset('styles/app.css')"));
        self::assertSame(1, substr_count($layout, "importmap('app')"));

        foreach ($rendered as $path => $template) {
            if (!str_starts_with($path, 'templates/bundles/Te4gUserBundle/')
                || str_starts_with($path, 'templates/bundles/Te4gUserBundle/email/')
                || str_ends_with($path, '/layout.html.twig')
            ) {
                continue;
            }

            self::assertStringContainsString("{% extends 'bundles/Te4gUserBundle/layout.html.twig' %}", $template, $path);
            self::assertStringNotContainsString('<!doctype html>', $template, $path);
            self::assertStringNotContainsString("importmap('app')", $template, $path);
        }
    }

    public function testFormsPreserveFieldCsrfRouteAndAccessibleDialogContracts(): void
    {
        $rendered = (new SkeletonRenderer())->renderAll(ProjectLayout::at($this->projectDirectory));
        $login = $rendered['templates/bundles/Te4gUserBundle/security/login.html.twig'];
        self::assertStringContainsString('name="_username"', $login);
        self::assertStringContainsString('name="_password"', $login);
        self::assertStringContainsString("csrf_token('authenticate')", $login);
        self::assertStringContainsString("path('te4g_user_login')", $login);

        $loginLink = $rendered['templates/bundles/Te4gUserBundle/security/login_link_check.html.twig'];
        self::assertStringContainsString("csrf_token('te4g_user_login_link_check')", $loginLink);

        $registration = $rendered['templates/bundles/Te4gUserBundle/registration/register.html.twig'];
        foreach (['form.email', 'form.plainPassword.first', 'form.plainPassword.second'] as $field) {
            self::assertStringContainsString($field, $registration);
        }

        $account = $rendered['templates/bundles/Te4gUserBundle/account/index.html.twig'];
        self::assertStringContainsString('<twig:AlertDialog', $account);
        $dialog = $rendered['templates/components/AlertDialog/Content.html.twig'];
        self::assertStringContainsString('<dialog', $dialog);
        self::assertStringContainsString('aria-labelledby="{{ _alertDialog_titleId }}"', $dialog);
        self::assertStringContainsString('aria-describedby="{{ _alertDialog_descriptionId }}"', $dialog);
        self::assertStringContainsString("'data-alert-dialog-target': 'form'", $account);
        self::assertStringContainsString('form_start(deletion_form,', $account);
        self::assertStringContainsString('data-action="click->alert-dialog#submit"', $account);
        self::assertStringContainsString('form_widget(deletion_form._token)', $account);
    }

    public function testStimulusControllersAreSyntacticallyValidAndOneConcernEach(): void
    {
        $rendered = (new SkeletonRenderer())->renderAll(ProjectLayout::at($this->projectDirectory));
        foreach (['password_visibility_controller.js', 'alert_dialog_controller.js'] as $controller) {
            $path = $this->projectDirectory.'/'.$controller;
            file_put_contents($path, $rendered['assets/controllers/'.$controller]);
            $process = new Process(['node', '--check', $path]);
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        $password = $rendered['assets/controllers/password_visibility_controller.js'];
        self::assertStringContainsString("static targets = ['input', 'button', 'label']", $password);
        self::assertStringContainsString("setAttribute('aria-pressed'", $password);
        self::assertStringNotContainsString('classList', $password);
        self::assertStringNotContainsString('innerHTML', $password);

        $dialog = $rendered['assets/controllers/alert_dialog_controller.js'];
        self::assertStringContainsString("static targets = ['trigger', 'dialog', 'form']", $dialog);
        self::assertStringContainsString('requestSubmit()', $dialog);
        self::assertStringContainsString('disconnect()', $dialog);
    }

    public function testImportMapCssTranslationAndIconInputsAreDeterministic(): void
    {
        $rendered = (new SkeletonRenderer())->renderAll(ProjectLayout::at($this->projectDirectory));
        self::assertSame([
            'shadcn/dist/tailwind.css',
            'tw-animate-css/dist/tw-animate.css',
        ], json_decode($rendered['importmap.required.json'], true, flags: JSON_THROW_ON_ERROR));

        $css = $rendered['assets/styles/te4g_user.css.fragment'];
        self::assertStringContainsString('@import "tailwindcss";', $css);
        self::assertStringContainsString('@import "../vendor/shadcn/dist/tailwind.css";', $css);
        self::assertStringContainsString('@import "../vendor/tw-animate-css/dist/tw-animate.css";', $css);
        self::assertSame(1, substr_count($css, '/* <te4g-user name="shadcn"> */'));
        self::assertSame(1, substr_count($css, '/* </te4g-user> */'));

        $translationPath = $this->projectDirectory.'/Te4gUserBundle.en.xlf';
        file_put_contents($translationPath, $rendered['translations/Te4gUserBundle.en.xlf']);
        $document = new \DOMDocument();
        self::assertTrue($document->load($translationPath));

        $iconNames = [];
        foreach ($rendered as $contents) {
            if (preg_match_all('/lucide:([a-z0-9-]+)/', $contents, $matches)) {
                $iconNames = [...$iconNames, ...$matches[1]];
            }
        }
        self::assertEqualsCanonicalizing(self::ICONS, array_values(array_unique($iconNames)));
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = dirname(__DIR__, 2).'/resources/shadcn/manifest.json';
        self::assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }
}
