<?php

declare(strict_types=1);

namespace App\Security;

use App\Account\EmailNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class LoginRequestRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        private EmailNormalizer $emailNormalizer,
        #[Autowire(service: 'limiter.account_login')]
        private RateLimiterFactoryInterface $loginLimiter,
    ) {
    }

    /** @return list<LimiterInterface> */
    protected function getLimiters(Request $request): array
    {
        $identifier = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');
        $normalizedIdentifier = $this->emailNormalizer->normalize($identifier);
        $clientAddress = $request->getClientIp() ?? 'unknown';
        $key = hash('sha256', $normalizedIdentifier."\0".$clientAddress);

        return [$this->loginLimiter->create($key)];
    }
}
