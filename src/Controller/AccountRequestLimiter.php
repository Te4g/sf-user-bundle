<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Te4g\UserBundle\Util\EmailNormalizer;

final readonly class AccountRequestLimiter
{
    public function __construct(
        private RateLimiterFactoryInterface $factory,
        private EmailNormalizer $emailNormalizer = new EmailNormalizer(),
    ) {
    }

    public function accept(Request $request, string $email): bool
    {
        $key = ($request->getClientIp() ?? 'unknown').':'.hash(
            'sha256',
            $this->emailNormalizer->normalize($email),
        );

        return $this->factory->create($key)->consume()->isAccepted();
    }
}
