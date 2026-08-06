<?php

declare(strict_types=1);

namespace Te4g\Starter\Project;

use Symfony\Component\Yaml\Yaml;

final readonly class ProjectInspector
{
    /** @var list<string> */
    private const array ACCOUNT_PATHS = [
        'src/Entity/User.php',
        'src/Repository/UserRepository.php',
        'src/Controller/AccountController.php',
        'src/Controller/DeleteAccountController.php',
        'src/Controller/LoginController.php',
        'src/Controller/MagicLinkController.php',
        'src/Controller/PasswordController.php',
        'src/Controller/PasswordResetController.php',
        'src/Controller/ProfileController.php',
        'src/Controller/RegistrationController.php',
        'src/Controller/VerificationController.php',
    ];

    public function __construct(
        private string $phpVersion,
        private string $symfonyVersion,
    ) {
    }

    public function inspect(ProjectLayout $layout): PreflightReport
    {
        $conflicts = [];

        if (!version_compare($this->phpVersion, '8.4.0', '>=')) {
            $conflicts[] = new Conflict('composer.json', 'PHP 8.4 or newer is required');
        }
        if (!version_compare($this->symfonyVersion, '8.1.0', '>=') || version_compare($this->symfonyVersion, '8.2.0', '>=')) {
            $conflicts[] = new Conflict('composer.json', 'Symfony 8.1.x is required');
        }

        $composer = $this->composerConfiguration($layout, $conflicts);
        if (null !== $composer) {
            $requirements = $composer['require'] ?? null;
            if (!is_array($requirements) || !array_key_exists('symfony/framework-bundle', $requirements)) {
                $conflicts[] = new Conflict('composer.json', 'symfony/framework-bundle is required');
            }
        }

        foreach (self::ACCOUNT_PATHS as $relativePath) {
            if (is_file($layout->path($relativePath))) {
                $conflicts[] = new Conflict($relativePath, 'an account system already exists');
            }
        }

        $this->inspectSecurityConfiguration($layout, $conflicts);

        return new PreflightReport($conflicts);
    }

    /** @param list<Conflict> $conflicts
     * @return array<mixed>|null
     */
    private function composerConfiguration(ProjectLayout $layout, array &$conflicts): ?array
    {
        $contents = file_get_contents($layout->path('composer.json'));
        if (false === $contents) {
            $conflicts[] = new Conflict('composer.json', 'Composer configuration cannot be read');

            return null;
        }

        try {
            $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $conflicts[] = new Conflict('composer.json', 'Composer configuration is invalid JSON');

            return null;
        }

        if (!is_array($composer)) {
            $conflicts[] = new Conflict('composer.json', 'Composer configuration must be an object');

            return null;
        }

        return $composer;
    }

    /** @param list<Conflict> $conflicts */
    private function inspectSecurityConfiguration(ProjectLayout $layout, array &$conflicts): void
    {
        $relativePath = 'config/packages/security.yaml';
        $path = $layout->path($relativePath);
        if (!is_file($path)) {
            return;
        }

        try {
            $configuration = Yaml::parseFile($path);
        } catch (\Throwable) {
            $conflicts[] = new Conflict($relativePath, 'security configuration is invalid');

            return;
        }

        if (!is_array($configuration) || !is_array($configuration['security'] ?? null)) {
            $conflicts[] = new Conflict($relativePath, 'security configuration is invalid');

            return;
        }

        $security = $configuration['security'];
        if (array_key_exists('providers', $security) && !$this->usesDefaultProvider($security['providers'])) {
            $conflicts[] = new Conflict($relativePath, 'a non-default security provider is already configured');
        }
        if (array_key_exists('firewalls', $security) && !$this->usesDefaultFirewall($security['firewalls'])) {
            $conflicts[] = new Conflict($relativePath, 'a non-default security firewall is already configured');
        }
    }

    private function usesDefaultProvider(mixed $providers): bool
    {
        return [] === $providers || ['users_in_memory' => ['memory' => null]] === $providers;
    }

    private function usesDefaultFirewall(mixed $firewalls): bool
    {
        if (!is_array($firewalls)) {
            return false;
        }

        foreach ($firewalls as $name => $firewall) {
            if ('dev' === $name) {
                continue;
            }
            if ('main' !== $name || ['lazy' => true] !== $firewall) {
                return false;
            }
        }

        return true;
    }
}
