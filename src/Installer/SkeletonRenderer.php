<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

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
            throw new \RuntimeException('Invalid application namespace configuration.');
        }

        try {
            $composer = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Invalid application namespace configuration.');
        }

        $psr4 = is_array($composer) && isset($composer['autoload']) && is_array($composer['autoload'])
            ? ($composer['autoload']['psr-4'] ?? null)
            : null;
        if (!is_array($psr4)) {
            return 'App';
        }

        foreach ($psr4 as $namespace => $directory) {
            $directories = is_array($directory) ? $directory : [$directory];
            foreach ($directories as $candidate) {
                if (is_string($candidate) && 'src' === trim(str_replace('\\', '/', $candidate), '/')) {
                    if (!is_string($namespace)) {
                        break 2;
                    }

                    $namespace = rtrim($namespace, '\\');
                    if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace)) {
                        throw new \RuntimeException('Invalid application namespace configuration.');
                    }

                    return $namespace;
                }
            }
        }

        return 'App';
    }
}
