<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;

final readonly class SkeletonRenderer
{
    private const array TEMPLATES = [
        'config/packages/reset_password.yaml.tpl' => 'config/packages/reset_password.yaml',
        'config/packages/te4g_user.yaml.tpl' => 'config/packages/te4g_user.yaml',
        'config/packages/te4g_user_security.yaml.tpl' => 'config/packages/te4g_user_security.yaml',
        'config/packages/te4g_user_twig.yaml.tpl' => 'config/packages/te4g_user_twig.yaml',
        'config/packages/verify_email.yaml.tpl' => 'config/packages/verify_email.yaml',
        'config/routes/te4g_user.yaml.tpl' => 'config/routes/te4g_user.yaml',
        'src/Entity/ResetPasswordRequest.php.tpl' => 'src/Entity/ResetPasswordRequest.php',
        'src/Entity/User.php.tpl' => 'src/Entity/User.php',
        'src/Repository/ResetPasswordRequestRepository.php.tpl' => 'src/Repository/ResetPasswordRequestRepository.php',
        'src/Repository/UserRepository.php.tpl' => 'src/Repository/UserRepository.php',
        'assets/controllers/alert_dialog_controller.js' => 'assets/controllers/alert_dialog_controller.js',
        'assets/controllers/password_visibility_controller.js' => 'assets/controllers/password_visibility_controller.js',
        'assets/icons/lucide/circle-check.svg' => 'assets/icons/lucide/circle-check.svg',
        'assets/icons/lucide/eye-off.svg' => 'assets/icons/lucide/eye-off.svg',
        'assets/icons/lucide/eye.svg' => 'assets/icons/lucide/eye.svg',
        'assets/icons/lucide/key-round.svg' => 'assets/icons/lucide/key-round.svg',
        'assets/icons/lucide/mail.svg' => 'assets/icons/lucide/mail.svg',
        'assets/icons/lucide/trash-2.svg' => 'assets/icons/lucide/trash-2.svg',
        'assets/icons/lucide/triangle-alert.svg' => 'assets/icons/lucide/triangle-alert.svg',
        'assets/styles/te4g_user.css.fragment' => 'assets/styles/te4g_user.css.fragment',
        'importmap.required.json' => 'importmap.required.json',
        'templates/bundles/Te4gUserBundle/account/index.html.twig' => 'templates/bundles/Te4gUserBundle/account/index.html.twig',
        'templates/bundles/Te4gUserBundle/account/password.html.twig' => 'templates/bundles/Te4gUserBundle/account/password.html.twig',
        'templates/bundles/Te4gUserBundle/account/profile.html.twig' => 'templates/bundles/Te4gUserBundle/account/profile.html.twig',
        'templates/bundles/Te4gUserBundle/email/email_change.html.twig' => 'templates/bundles/Te4gUserBundle/email/email_change.html.twig',
        'templates/bundles/Te4gUserBundle/email/magic_link.html.twig' => 'templates/bundles/Te4gUserBundle/email/magic_link.html.twig',
        'templates/bundles/Te4gUserBundle/email/password_reset.html.twig' => 'templates/bundles/Te4gUserBundle/email/password_reset.html.twig',
        'templates/bundles/Te4gUserBundle/email/verify.html.twig' => 'templates/bundles/Te4gUserBundle/email/verify.html.twig',
        'templates/bundles/Te4gUserBundle/layout.html.twig' => 'templates/bundles/Te4gUserBundle/layout.html.twig',
        'templates/bundles/Te4gUserBundle/password/request.html.twig' => 'templates/bundles/Te4gUserBundle/password/request.html.twig',
        'templates/bundles/Te4gUserBundle/password/reset.html.twig' => 'templates/bundles/Te4gUserBundle/password/reset.html.twig',
        'templates/bundles/Te4gUserBundle/registration/register.html.twig' => 'templates/bundles/Te4gUserBundle/registration/register.html.twig',
        'templates/bundles/Te4gUserBundle/security/check_email.html.twig' => 'templates/bundles/Te4gUserBundle/security/check_email.html.twig',
        'templates/bundles/Te4gUserBundle/security/login.html.twig' => 'templates/bundles/Te4gUserBundle/security/login.html.twig',
        'templates/bundles/Te4gUserBundle/security/login_link_check.html.twig' => 'templates/bundles/Te4gUserBundle/security/login_link_check.html.twig',
        'templates/components/Alert.html.twig' => 'templates/components/Alert.html.twig',
        'templates/components/Alert/Action.html.twig' => 'templates/components/Alert/Action.html.twig',
        'templates/components/Alert/Description.html.twig' => 'templates/components/Alert/Description.html.twig',
        'templates/components/Alert/Title.html.twig' => 'templates/components/Alert/Title.html.twig',
        'templates/components/AlertDialog.html.twig' => 'templates/components/AlertDialog.html.twig',
        'templates/components/AlertDialog/Action.html.twig' => 'templates/components/AlertDialog/Action.html.twig',
        'templates/components/AlertDialog/Cancel.html.twig' => 'templates/components/AlertDialog/Cancel.html.twig',
        'templates/components/AlertDialog/Content.html.twig' => 'templates/components/AlertDialog/Content.html.twig',
        'templates/components/AlertDialog/Description.html.twig' => 'templates/components/AlertDialog/Description.html.twig',
        'templates/components/AlertDialog/Footer.html.twig' => 'templates/components/AlertDialog/Footer.html.twig',
        'templates/components/AlertDialog/Header.html.twig' => 'templates/components/AlertDialog/Header.html.twig',
        'templates/components/AlertDialog/Media.html.twig' => 'templates/components/AlertDialog/Media.html.twig',
        'templates/components/AlertDialog/Title.html.twig' => 'templates/components/AlertDialog/Title.html.twig',
        'templates/components/AlertDialog/Trigger.html.twig' => 'templates/components/AlertDialog/Trigger.html.twig',
        'templates/components/Button.html.twig' => 'templates/components/Button.html.twig',
        'templates/components/Card.html.twig' => 'templates/components/Card.html.twig',
        'templates/components/Card/Action.html.twig' => 'templates/components/Card/Action.html.twig',
        'templates/components/Card/Content.html.twig' => 'templates/components/Card/Content.html.twig',
        'templates/components/Card/Description.html.twig' => 'templates/components/Card/Description.html.twig',
        'templates/components/Card/Footer.html.twig' => 'templates/components/Card/Footer.html.twig',
        'templates/components/Card/Header.html.twig' => 'templates/components/Card/Header.html.twig',
        'templates/components/Card/Title.html.twig' => 'templates/components/Card/Title.html.twig',
        'templates/components/Field.html.twig' => 'templates/components/Field.html.twig',
        'templates/components/Field/Content.html.twig' => 'templates/components/Field/Content.html.twig',
        'templates/components/Field/Description.html.twig' => 'templates/components/Field/Description.html.twig',
        'templates/components/Field/Error.html.twig' => 'templates/components/Field/Error.html.twig',
        'templates/components/Field/Group.html.twig' => 'templates/components/Field/Group.html.twig',
        'templates/components/Field/Label.html.twig' => 'templates/components/Field/Label.html.twig',
        'templates/components/Field/Legend.html.twig' => 'templates/components/Field/Legend.html.twig',
        'templates/components/Field/Separator.html.twig' => 'templates/components/Field/Separator.html.twig',
        'templates/components/Field/Set.html.twig' => 'templates/components/Field/Set.html.twig',
        'templates/components/Field/Title.html.twig' => 'templates/components/Field/Title.html.twig',
        'templates/components/Input.html.twig' => 'templates/components/Input.html.twig',
        'templates/components/Label.html.twig' => 'templates/components/Label.html.twig',
        'templates/components/Separator.html.twig' => 'templates/components/Separator.html.twig',
        'templates/components/Spinner.html.twig' => 'templates/components/Spinner.html.twig',
        'templates/form/te4g_user_shadcn_theme.html.twig' => 'templates/form/te4g_user_shadcn_theme.html.twig',
        'translations/Te4gUserBundle.en.xlf' => 'translations/Te4gUserBundle.en.xlf',
    ];

    public function __construct(
        private string $bundleVersion = '0.1.0',
        private ?string $resourceDirectory = null,
    ) {
    }

    /** @return array<string, string> */
    public function renderAll(ProjectLayout $layout): array
    {
        $namespace = $this->applicationNamespace($layout);
        $tokens = [
            '__APP_NAMESPACE__' => $namespace,
            '__USER_CLASS__' => $namespace.'\\Entity\\User',
            '__USER_REPOSITORY__' => $namespace.'\\Repository\\UserRepository',
            '__BUNDLE_VERSION__' => $this->bundleVersion,
        ];
        $renderedFiles = [];

        foreach (self::TEMPLATES as $template => $target) {
            $contents = file_get_contents($this->resources().'/'.$template);
            if (false === $contents) {
                throw new \LogicException(sprintf('Unable to read skeleton template "%s".', $template));
            }

            $rendered = strtr($contents, $tokens);
            if (1 === preg_match('/__[A-Z_]+__/', $rendered, $match)) {
                throw new \LogicException(sprintf('Unresolved skeleton token "%s" in "%s".', $match[0], $template));
            }

            $renderedFiles[$target] = $rendered;
        }

        return $renderedFiles;
    }

    private function resources(): string
    {
        return $this->resourceDirectory ?? dirname(__DIR__, 2).'/resources/skeleton';
    }

    private function applicationNamespace(ProjectLayout $layout): string
    {
        $composerPath = $layout->path('composer.json');
        if (!is_file($composerPath)) {
            return 'App';
        }

        $json = file_get_contents($composerPath);
        if (false === $json) {
            throw InstallerConflict::invalidProjectConfiguration();
        }

        try {
            $composer = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InstallerConflict::invalidProjectConfiguration();
        }

        $psr4 = is_array($composer) && isset($composer['autoload']) && is_array($composer['autoload'])
            ? ($composer['autoload']['psr-4'] ?? null)
            : null;
        if (!is_array($psr4)) {
            throw InstallerConflict::invalidProjectConfiguration();
        }

        $sourceNamespaces = [];
        foreach ($psr4 as $namespace => $directory) {
            $directories = is_array($directory) ? $directory : [$directory];
            foreach ($directories as $candidate) {
                if (!$this->mapsProjectSource($candidate)) {
                    continue;
                }

                if (!is_string($namespace) || !str_ends_with($namespace, '\\')) {
                    throw InstallerConflict::invalidProjectConfiguration();
                }

                $applicationNamespace = substr($namespace, 0, -1);
                if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $applicationNamespace)) {
                    throw InstallerConflict::invalidProjectConfiguration();
                }

                $sourceNamespaces[$applicationNamespace] = true;

                break;
            }
        }

        if (1 !== count($sourceNamespaces)) {
            throw InstallerConflict::invalidProjectConfiguration();
        }

        return array_key_first($sourceNamespaces);
    }

    private function mapsProjectSource(mixed $directory): bool
    {
        if (!is_string($directory) || '' === $directory || str_contains($directory, "\0")) {
            return false;
        }

        $portablePath = str_replace('\\', '/', $directory);
        if (str_starts_with($portablePath, '/') || 1 === preg_match('/^[A-Za-z]:\//', $portablePath)) {
            return false;
        }

        $segments = explode('/', $portablePath);
        if (in_array('..', $segments, true)) {
            return false;
        }

        $segments = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => '' !== $segment && '.' !== $segment,
        ));

        return ['src'] === $segments;
    }
}
