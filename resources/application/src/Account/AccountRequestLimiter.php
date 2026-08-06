<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class AccountRequestLimiter
{
    public function __construct(
        private EmailNormalizer $normalizer,
        #[Autowire(service: 'limiter.account_registration')]
        private RateLimiterFactoryInterface $registrationLimiter,
        #[Autowire(service: 'limiter.account_magic_link')]
        private RateLimiterFactoryInterface $magicLinkLimiter,
        #[Autowire(service: 'limiter.account_password_reset')]
        private RateLimiterFactoryInterface $passwordResetLimiter,
        #[Autowire(service: 'limiter.account_verification_resend')]
        private RateLimiterFactoryInterface $verificationResendLimiter,
        #[Autowire(service: 'limiter.account_email_change')]
        private RateLimiterFactoryInterface $emailChangeLimiter,
    ) {
    }

    public function registration(Request $request, string $email): bool
    {
        return $this->accept($this->registrationLimiter, $request, $email);
    }

    public function magicLink(Request $request, string $email): bool
    {
        return $this->accept($this->magicLinkLimiter, $request, $email);
    }

    public function passwordReset(Request $request, string $email): bool
    {
        return $this->accept($this->passwordResetLimiter, $request, $email);
    }

    public function verificationResend(Request $request, string $email): bool
    {
        return $this->accept($this->verificationResendLimiter, $request, $email);
    }

    public function emailChange(Request $request, string $email): bool
    {
        return $this->accept($this->emailChangeLimiter, $request, $email);
    }

    private function accept(RateLimiterFactoryInterface $factory, Request $request, string $email): bool
    {
        $key = hash('sha256', ($request->getClientIp() ?? 'unknown')."\0".$this->normalizer->normalize($email));

        return $factory->create($key)->consume()->isAccepted();
    }
}
