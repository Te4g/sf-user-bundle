<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Yaml\Yaml;
use Te4g\UserBundle\Exception\InstallerConflict;

final readonly class PreflightChecker
{
    private const string TAILWIND_RUNTIME_PREFIX = 'var/tailwind/';

    /** @var list<string> */
    private const array REQUIRED_BUNDLES = [
        'Doctrine\\Bundle\\DoctrineBundle\\DoctrineBundle',
        'Doctrine\\Bundle\\MigrationsBundle\\DoctrineMigrationsBundle',
        'Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle',
        'Symfony\\Bundle\\SecurityBundle\\SecurityBundle',
        'Symfony\\Bundle\\TwigBundle\\TwigBundle',
        'Symfony\\UX\\Icons\\UXIconsBundle',
        'Symfony\\UX\\StimulusBundle\\StimulusBundle',
        'Symfony\\UX\\Turbo\\TurboBundle',
        'Symfony\\UX\\TwigComponent\\TwigComponentBundle',
        'SymfonyCasts\\Bundle\\ResetPassword\\SymfonyCastsResetPasswordBundle',
        'SymfonyCasts\\Bundle\\VerifyEmail\\SymfonyCastsVerifyEmailBundle',
        'Symfonycasts\\TailwindBundle\\SymfonycastsTailwindBundle',
        'Te4g\\UserBundle\\Te4gUserBundle',
    ];

    /** @var list<string> */
    private const array REQUIRED_DIRECTORIES = [
        'assets',
        'assets/controllers',
        'assets/styles',
        'config',
        'config/packages',
        'config/routes',
        'migrations',
        'src',
        'src/Entity',
        'src/Repository',
        'templates',
        'translations',
    ];

    /** @var list<string> */
    private const array REQUIRED_FILES = [
        'assets/app.js',
        'assets/controllers.json',
        'assets/styles/app.css',
        'bin/console',
        'composer.json',
        'config/bundles.php',
        'importmap.php',
    ];

    /** @var list<string> */
    private const array ROUTE_NAMES = [
        'te4g_user_account',
        'te4g_user_account_delete',
        'te4g_user_check_email',
        'te4g_user_email_change_confirm',
        'te4g_user_login',
        'te4g_user_login_link_check',
        'te4g_user_login_link_request',
        'te4g_user_logout',
        'te4g_user_password_change',
        'te4g_user_password_request',
        'te4g_user_password_reset',
        'te4g_user_profile',
        'te4g_user_register',
        'te4g_user_verify_email',
        'te4g_user_verify_resend',
    ];

    /** @var list<string> */
    private const array ACCOUNT_CONTROLLERS = [
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

    private string $phpVersion;
    private string $symfonyVersion;

    public function __construct(
        private ManifestStore $manifestStore,
        private CssFragmentMerger $cssFragmentMerger,
        ?string $phpVersion = null,
        ?string $symfonyVersion = null,
    ) {
        $this->phpVersion = $phpVersion ?? PHP_VERSION;
        $this->symfonyVersion = $symfonyVersion ?? Kernel::VERSION;
    }

    /** @return list<InstallerConflict> */
    public function check(ProjectLayout $layout): array
    {
        /** @var array<string, InstallerConflict> $conflicts */
        $conflicts = [];
        $add = static function (InstallerConflict $conflict) use (&$conflicts): void {
            $key = ($conflict->relativePath() ?? '').'|'.$conflict->code();
            $conflicts[$key] = $conflict;
        };

        if (!version_compare($this->phpVersion, '8.4.0', '>=') || version_compare($this->phpVersion, '9.0.0', '>=')) {
            $add(InstallerConflict::forPathless('php-version-unsupported'));
        }
        if (!version_compare($this->symfonyVersion, '8.1.0', '>=') || version_compare($this->symfonyVersion, '9.0.0', '>=')) {
            $add(InstallerConflict::forPathless('symfony-version-unsupported'));
        }

        foreach (self::REQUIRED_DIRECTORIES as $relativePath) {
            $this->checkDirectory($layout, $relativePath, $add);
        }
        foreach (self::REQUIRED_FILES as $relativePath) {
            $this->checkFile($layout, $relativePath, $add);
        }

        $manifest = $this->loadManifest($layout, $add);
        $this->checkBundles($layout, $add);
        $this->checkAssetMapperPaths($layout, $add);
        $this->checkFirewall($layout, $add);
        $this->checkRoutes($layout, $add);
        $this->checkGeneratedTargets($layout, $manifest, $add);
        $this->checkAccountControllers($layout, $manifest, $add);
        $this->checkSpecialOwnedTarget($layout, $manifest, 'config/packages/te4g_user_tailwind.yaml', $add);
        $this->checkCss($layout, $add);
        $this->checkWritableTargets($layout, $add);

        $conflicts = array_values($conflicts);
        usort($conflicts, static function (InstallerConflict $left, InstallerConflict $right): int {
            return [
                $left->relativePath() ?? '',
                $left->code(),
            ] <=> [
                $right->relativePath() ?? '',
                $right->code(),
            ];
        });

        return $conflicts;
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkDirectory(ProjectLayout $layout, string $relativePath, callable $add): void
    {
        try {
            $path = $layout->path($relativePath);
        } catch (InstallerConflict) {
            $add(InstallerConflict::forPath($relativePath, 'unsafe-target-path'));

            return;
        }

        if (!is_dir($path) || is_link($path)) {
            $add(InstallerConflict::forPath($relativePath, 'required-directory-missing'));
        }
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkFile(ProjectLayout $layout, string $relativePath, callable $add): void
    {
        try {
            $path = $layout->path($relativePath);
        } catch (InstallerConflict) {
            $add(InstallerConflict::forPath($relativePath, 'unsafe-target-path'));

            return;
        }

        if (!is_file($path) || is_link($path)) {
            $add(InstallerConflict::forPath($relativePath, 'required-file-missing'));
        }
    }

    /** @param callable(InstallerConflict): void $add */
    private function loadManifest(ProjectLayout $layout, callable $add): ?InstallationManifest
    {
        try {
            $manifest = $this->manifestStore->load($layout);
        } catch (InstallerConflict $conflict) {
            $add($conflict);

            return null;
        }

        if (null === $manifest) {
            return null;
        }

        foreach ($manifest->files() as $relativePath => $expectedHash) {
            if (str_starts_with($relativePath, self::TAILWIND_RUNTIME_PREFIX)) {
                continue;
            }
            try {
                $path = $layout->path($relativePath);
            } catch (InstallerConflict) {
                $add(InstallerConflict::forPath($relativePath, 'unsafe-target-path'));

                continue;
            }

            if (!is_file($path) || is_link($path)) {
                $add(InstallerConflict::forPath($relativePath, 'managed-file-missing'));

                continue;
            }

            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals($expectedHash, $hash)) {
                $add(InstallerConflict::forPath($relativePath, 'modified-managed-file'));
            }
        }

        return $manifest;
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkBundles(ProjectLayout $layout, callable $add): void
    {
        try {
            $path = $layout->path('config/bundles.php');
        } catch (InstallerConflict) {
            return;
        }
        if (!is_file($path) || is_link($path)) {
            return;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            $add(InstallerConflict::forPath('config/bundles.php', 'unreadable-project-file'));

            return;
        }

        $registered = $this->phpClassConstants($contents);
        foreach (self::REQUIRED_BUNDLES as $bundle) {
            if (!in_array($bundle, $registered, true)) {
                $add(InstallerConflict::forPath('config/bundles.php', 'missing-bundle-'.$this->codePart($bundle)));
            }
        }
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkAssetMapperPaths(ProjectLayout $layout, callable $add): void
    {
        try {
            $directory = $layout->path('config/packages');
        } catch (InstallerConflict) {
            return;
        }
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $info) {
            if (!$info->isFile() || $info->isLink() || !preg_match('/\.(?:php|ya?ml)$/i', $info->getFilename())) {
                continue;
            }
            $files[] = $info->getPathname();
        }
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            $relativePath = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($path, strlen($layout->projectDir()) + 1),
            );
            if (str_ends_with(strtolower($path), '.php')) {
                $this->checkAssetMapperPhp($path, $relativePath, $layout, $add);

                continue;
            }
            try {
                $config = Yaml::parseFile($path);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($config)) {
                $this->checkAssetMapperNode($config, $layout, $relativePath, $add);
            }
        }
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkAssetMapperPhp(
        string $path,
        string $relativePath,
        ProjectLayout $layout,
        callable $add,
    ): void {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            $add(InstallerConflict::forPath($relativePath, 'uninspectable-asset-mapper-configuration'));

            return;
        }

        try {
            $tokens = array_values(\PhpToken::tokenize($contents, TOKEN_PARSE));
        } catch (\Throwable) {
            $add(InstallerConflict::forPath($relativePath, 'uninspectable-asset-mapper-configuration'));

            return;
        }

        $hasAssetMapper = false;
        /** @var list<int> $storageCalls */
        $storageCalls = [];
        foreach ($tokens as $index => $token) {
            if (T_STRING !== $token->id || !$this->isPhpMethodCall($tokens, $index)) {
                continue;
            }

            $method = strtolower($token->text);
            if ('assetmapper' === $method) {
                $hasAssetMapper = true;
            } elseif (in_array($method, ['importmappath', 'vendordir'], true)) {
                $storageCalls[] = $index;
            }
        }
        if (!$hasAssetMapper) {
            return;
        }

        foreach ($storageCalls as $index) {
            $method = strtolower($tokens[$index]->text);
            $open = $this->nextPhpToken($tokens, $index + 1);
            $value = null === $open ? null : $this->nextPhpToken($tokens, $open + 1);
            $close = null === $value ? null : $this->nextPhpToken($tokens, $value + 1);
            if (null === $open
                || '(' !== $tokens[$open]->text
                || null === $value
                || T_CONSTANT_ENCAPSED_STRING !== $tokens[$value]->id
                || null === $close
                || ')' !== $tokens[$close]->text
            ) {
                $add(InstallerConflict::forPath($relativePath, 'uninspectable-asset-mapper-configuration'));

                continue;
            }

            $literal = $this->phpStringLiteral($tokens[$value]->text);
            if (null === $literal) {
                $add(InstallerConflict::forPath($relativePath, 'uninspectable-asset-mapper-configuration'));

                continue;
            }

            $key = 'importmappath' === $method ? 'importmap_path' : 'vendor_dir';
            if (!in_array($literal, $this->allowedAssetMapperPaths($key, $layout), true)) {
                $add(InstallerConflict::forPath(
                    $relativePath,
                    'asset-mapper-'.str_replace('_', '-', $key).'-conflict',
                ));
            }
        }
    }

    /**
     * @param array<int|string, mixed>          $config
     * @param callable(InstallerConflict): void $add
     */
    private function checkAssetMapperNode(array $config, ProjectLayout $layout, string $relativePath, callable $add): void
    {
        foreach ($config as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'when@') && is_array($value)) {
                $this->checkAssetMapperNode($value, $layout, $relativePath, $add);
            }
        }

        $framework = $config['framework'] ?? null;
        if (!is_array($framework) || !array_key_exists('asset_mapper', $framework)) {
            return;
        }
        $assetMapper = $framework['asset_mapper'];
        if (!is_array($assetMapper)) {
            if (false === $assetMapper) {
                $add(InstallerConflict::forPath($relativePath, 'asset-mapper-importmap-path-conflict'));
                $add(InstallerConflict::forPath($relativePath, 'asset-mapper-vendor-dir-conflict'));
            }

            return;
        }

        foreach (['importmap_path', 'vendor_dir'] as $key) {
            if (array_key_exists($key, $assetMapper)
                && (!is_string($assetMapper[$key])
                    || !in_array($assetMapper[$key], $this->allowedAssetMapperPaths($key, $layout), true))
            ) {
                $add(InstallerConflict::forPath(
                    $relativePath,
                    'asset-mapper-'.str_replace('_', '-', $key).'-conflict',
                ));
            }
        }
    }

    /** @return list<string> */
    private function allowedAssetMapperPaths(string $key, ProjectLayout $layout): array
    {
        return 'importmap_path' === $key
            ? ['%kernel.project_dir%/importmap.php', $layout->projectDir().'/importmap.php']
            : ['%kernel.project_dir%/assets/vendor', $layout->projectDir().'/assets/vendor'];
    }

    /** @param list<\PhpToken> $tokens */
    private function isPhpMethodCall(array $tokens, int $index): bool
    {
        $previous = $this->previousPhpToken($tokens, $index - 1);
        $next = $this->nextPhpToken($tokens, $index + 1);

        return null !== $previous
            && in_array($tokens[$previous]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && null !== $next
            && '(' === $tokens[$next]->text;
    }

    /** @param list<\PhpToken> $tokens */
    private function previousPhpToken(array $tokens, int $offset): ?int
    {
        for ($index = $offset; $index >= 0; --$index) {
            if (!in_array($tokens[$index]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function nextPhpToken(array $tokens, int $offset): ?int
    {
        for ($index = $offset, $count = count($tokens); $index < $count; ++$index) {
            if (!in_array($tokens[$index]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    private function phpStringLiteral(string $literal): ?string
    {
        if (strlen($literal) < 2 || !in_array($literal[0], ["'", '"'], true)) {
            return null;
        }
        $value = substr($literal, 1, -1);
        if ("'" === $literal[0]) {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
        }
        if (1 === preg_match('/\\\\(?![\\\\"])/', $value)) {
            return null;
        }

        return strtr($value, ['\\\\' => '\\', '\\"' => '"']);
    }

    /** @return list<string> */
    private function phpClassConstants(string $contents): array
    {
        $classes = [];
        $tokens = token_get_all($contents);
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_STRING], true)) {
                continue;
            }

            $class = $token[1];
            $next = $this->nextSignificantToken($tokens, $index + 1);
            if ('::' !== $next['text']) {
                continue;
            }
            $classToken = $this->nextSignificantToken($tokens, $next['index'] + 1);
            if ('class' === strtolower($classToken['text'])) {
                $classes[] = ltrim($class, '\\');
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{index: int, text: string}
     */
    private function nextSignificantToken(array $tokens, int $offset): array
    {
        for ($index = $offset, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return ['index' => $index, 'text' => is_array($token) ? $token[1] : $token];
        }

        return ['index' => count($tokens), 'text' => ''];
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkFirewall(ProjectLayout $layout, callable $add): void
    {
        $relativePath = 'config/packages/security.yaml';
        try {
            $path = $layout->path($relativePath);
        } catch (InstallerConflict) {
            $add(InstallerConflict::forPath($relativePath, 'unsafe-target-path'));

            return;
        }
        if (!is_file($path)) {
            $add(InstallerConflict::forPath($relativePath, 'security-configuration-missing'));

            return;
        }

        try {
            $config = Yaml::parseFile($path);
        } catch (\Throwable) {
            $add(InstallerConflict::forPath($relativePath, 'invalid-security-configuration'));

            return;
        }
        if (!is_array($config)) {
            $add(InstallerConflict::forPath($relativePath, 'invalid-security-configuration'));

            return;
        }

        $firewalls = $config['security']['firewalls'] ?? null;
        if (!is_array($firewalls) || !array_key_exists('main', $firewalls) || !is_array($firewalls['main'])) {
            $add(InstallerConflict::forPath($relativePath, 'main-firewall-missing'));

            return;
        }
        $main = $firewalls['main'];
        $provider = $main['provider'] ?? null;
        if (null !== $provider && 'te4g_user_provider' !== $provider) {
            $add(InstallerConflict::forPath($relativePath, 'main-firewall-provider-conflict'));
        }

        foreach ([
            'access_token',
            'custom_authenticators',
            'form_login',
            'http_basic',
            'json_login',
            'login_link',
            'logout',
            'remote_user',
            'x509',
        ] as $authenticator) {
            if (array_key_exists($authenticator, $main)) {
                $add(InstallerConflict::forPath($relativePath, 'main-firewall-authenticator-conflict'));

                break;
            }
        }

        if (true === ($main['stateless'] ?? false)
            || false === ($main['security'] ?? true)
            || array_key_exists('pattern', $main)
            || array_key_exists('request_matcher', $main)
            || array_key_exists('host', $main)
            || array_key_exists('methods', $main)
        ) {
            $add(InstallerConflict::forPath($relativePath, 'main-firewall-mode-conflict'));
        }

        if (isset($main['user_checker']) && 'Te4g\\UserBundle\\Security\\VerifiedAccountUserChecker' !== $main['user_checker']) {
            $add(InstallerConflict::forPath($relativePath, 'main-firewall-user-checker-conflict'));
        }
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkRoutes(ProjectLayout $layout, callable $add): void
    {
        foreach ((new RouteConflictScanner())->scan($layout, self::ROUTE_NAMES) as $conflict) {
            $add($conflict);
        }
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkGeneratedTargets(
        ProjectLayout $layout,
        ?InstallationManifest $manifest,
        callable $add,
    ): void {
        foreach (SkeletonRenderer::targetPaths() as $relativePath) {
            if (in_array($relativePath, ['assets/styles/te4g_user.css.fragment', 'importmap.required.json'], true)) {
                continue;
            }
            try {
                $path = $layout->path($relativePath);
            } catch (InstallerConflict) {
                $add(InstallerConflict::forPath($relativePath, 'unsafe-target-path'));

                continue;
            }
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }
            if ($this->isManifestOwned($manifest, $relativePath, $path)) {
                continue;
            }
            $contents = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
            if (is_string($contents) && (FlexBootstrap::matches($manifest, $relativePath, $contents)
                || ResetPasswordRecipeBootstrap::matches($manifest, $relativePath, $contents))
            ) {
                continue;
            }

            $code = str_starts_with($relativePath, 'templates/components/')
                ? 'existing-component'
                : (in_array($relativePath, [
                    'src/Entity/ResetPasswordRequest.php',
                    'src/Entity/User.php',
                    'src/Repository/ResetPasswordRequestRepository.php',
                    'src/Repository/UserRepository.php',
                ], true) ? 'existing-application-class' : 'existing-generated-target');
            $add(InstallerConflict::forPath($relativePath, $code));
        }
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkAccountControllers(
        ProjectLayout $layout,
        ?InstallationManifest $manifest,
        callable $add,
    ): void {
        foreach (self::ACCOUNT_CONTROLLERS as $relativePath) {
            try {
                $path = $layout->path($relativePath);
            } catch (InstallerConflict) {
                $add(InstallerConflict::forPath($relativePath, 'unsafe-target-path'));

                continue;
            }
            if ((!file_exists($path) && !is_link($path)) || $this->isManifestOwned($manifest, $relativePath, $path)) {
                continue;
            }

            $add(InstallerConflict::forPath($relativePath, 'existing-account-controller'));
        }
    }

    private function isManifestOwned(?InstallationManifest $manifest, string $relativePath, string $path): bool
    {
        $expectedHash = $manifest?->hashFor($relativePath);
        if (null === $expectedHash || !is_file($path) || is_link($path)) {
            return false;
        }

        $hash = hash_file('sha256', $path);

        return is_string($hash) && hash_equals($expectedHash, $hash);
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkSpecialOwnedTarget(
        ProjectLayout $layout,
        ?InstallationManifest $manifest,
        string $relativePath,
        callable $add,
    ): void {
        try {
            $path = $layout->path($relativePath);
        } catch (InstallerConflict) {
            $add(InstallerConflict::forPath($relativePath, 'unsafe-target-path'));

            return;
        }
        if ((!file_exists($path) && !is_link($path)) || $this->isManifestOwned($manifest, $relativePath, $path)) {
            return;
        }

        $add(InstallerConflict::forPath($relativePath, 'existing-generated-target'));
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkCss(ProjectLayout $layout, callable $add): void
    {
        try {
            $target = $layout->path('assets/styles/app.css');
        } catch (InstallerConflict) {
            return;
        }
        if (!is_file($target) || is_link($target)) {
            return;
        }

        $stylesheet = file_get_contents($target);
        $fragment = file_get_contents(dirname(__DIR__, 2).'/resources/skeleton/assets/styles/te4g_user.css.fragment');
        if (!is_string($stylesheet) || !is_string($fragment)) {
            $add(InstallerConflict::forPath('assets/styles/app.css', 'unreadable-project-file'));

            return;
        }

        try {
            $this->cssFragmentMerger->merge($stylesheet, $fragment);
        } catch (InstallerConflict) {
            $add(InstallerConflict::forPath('assets/styles/app.css', 'invalid-css-fragment-target'));
        }
    }

    /** @param callable(InstallerConflict): void $add */
    private function checkWritableTargets(ProjectLayout $layout, callable $add): void
    {
        $targets = [
            ...SkeletonRenderer::targetPaths(),
            'assets/styles/app.css',
            'config/packages/te4g_user_tailwind.yaml',
            ManifestStore::RELATIVE_PATH,
            'importmap.php',
            'migrations/Version.php',
        ];

        foreach ($targets as $relativePath) {
            if (in_array($relativePath, ['assets/styles/te4g_user.css.fragment', 'importmap.required.json'], true)) {
                continue;
            }

            try {
                $path = $layout->path($relativePath);
            } catch (InstallerConflict) {
                $add(InstallerConflict::forPath($relativePath, 'unsafe-target-path'));

                continue;
            }

            if (file_exists($path) || is_link($path)) {
                if (is_link($path) || !is_file($path) || !is_writable($path)) {
                    $add(InstallerConflict::forPath($relativePath, 'target-not-writable'));
                }

                continue;
            }

            $ancestor = dirname($path);
            while (!file_exists($ancestor) && $ancestor !== $layout->projectDir()) {
                $ancestor = dirname($ancestor);
            }
            if (!is_dir($ancestor) || is_link($ancestor) || !is_writable($ancestor)) {
                $add(InstallerConflict::forPath($relativePath, 'target-parent-not-writable'));
            }
        }
    }

    private function codePart(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));
    }
}
