<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Configuration;

final readonly class UserBundleOptions
{
    /**
     * @param array<string, string> $redirects
     * @param array<string, string> $templates
     */
    public function __construct(
        public bool $enabled,
        public string $userClass,
        public string $accountRepository,
        public string $fromEmail,
        public string $fromName,
        public array $redirects,
        public array $templates,
        public bool $deletionEnabled,
    ) {
    }

    /**
     * @param array{
     *     enabled: bool,
     *     user_class: string,
     *     account_repository: string,
     *     mailer: array{from_email: string, from_name: string},
     *     redirects: array<string, string>,
     *     templates: array<string, string>,
     *     features: array{account_deletion: bool}
     * } $config
     */
    public static function fromConfiguration(array $config): self
    {
        return new self(
            $config['enabled'],
            $config['user_class'],
            $config['account_repository'],
            $config['mailer']['from_email'],
            $config['mailer']['from_name'],
            $config['redirects'],
            $config['templates'],
            $config['features']['account_deletion'],
        );
    }
}
