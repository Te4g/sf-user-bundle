<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class RegistrationChallenge
{
    public function __construct(
        #[Autowire(service: 'account.turnstile_http_client')]
        private HttpClientInterface $http,
        #[Autowire('%account.turnstile.enabled%')]
        private bool $enabled,
        #[Autowire(env: 'default::TURNSTILE_SITE_KEY')]
        private ?string $siteKey,
        #[Autowire(env: 'default::TURNSTILE_SECRET_KEY')]
        private ?string $secretKey,
        #[Autowire(env: 'default::TURNSTILE_HOSTNAME')]
        private ?string $hostname,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSiteKey(): ?string
    {
        return $this->enabled && $this->configured() ? $this->siteKey : null;
    }

    public function verify(#[\SensitiveParameter] mixed $token, ?string $clientIp): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if (!$this->configured() || !is_string($token) || '' === trim($token) || strlen($token) > 2048) {
            return false;
        }

        try {
            $body = ['secret' => $this->secretKey, 'response' => $token];
            if (null !== $clientIp) {
                $body['remoteip'] = $clientIp;
            }

            $response = $this->http->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => $body,
                'timeout' => 3,
                'max_duration' => 5,
                'max_redirects' => 0,
                'extra' => ['trace_content' => false],
            ]);
            if (200 !== $response->getStatusCode()) {
                return false;
            }

            $result = $response->toArray(false);

            return true === ($result['success'] ?? null)
                && 'register' === ($result['action'] ?? null)
                && $this->hostname === ($result['hostname'] ?? null);
        } catch (ExceptionInterface) {
            // Provider errors must neither accept registration nor expose tokens.
            return false;
        }
    }

    private function configured(): bool
    {
        if (null === $this->siteKey || '' === trim($this->siteKey) || null === $this->secretKey || '' === trim($this->secretKey) || null === $this->hostname || '' === trim($this->hostname)) {
            return false;
        }

        // Cloudflare publishes these deliberately permissive/rejecting dummy keys.
        return 'prod' !== $this->environment
            || (1 !== preg_match('/^[123]x0{10,}/', $this->siteKey) && 1 !== preg_match('/^[123]x0{10,}/', $this->secretKey));
    }
}
