<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\App;

use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class TestVerifyEmailHelper implements VerifyEmailHelperInterface
{
    /** @var list<array{signedUrl: string, userId: string, userEmail: string}> */
    public array $validatedConfirmations = [];

    private bool $rejectNextConfirmation = false;

    /** @param array<string, mixed> $extraParams */
    public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
    {
        $path = 'te4g_user_email_change_confirm' === $routeName ? '/account/email-change' : '/verify-email';

        return new VerifyEmailSignatureComponents(
            new \DateTimeImmutable('+1 hour'),
            'https://localhost'.$path.'?id='.rawurlencode($userId).'&signature=test',
            time(),
        );
    }

    public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
    {
        $this->validatedConfirmations[] = [
            'signedUrl' => $signedUrl,
            'userId' => $userId,
            'userEmail' => $userEmail,
        ];

        if ($this->rejectNextConfirmation) {
            $this->rejectNextConfirmation = false;

            throw new InvalidSignatureException();
        }
    }

    public function rejectNextConfirmation(): void
    {
        $this->rejectNextConfirmation = true;
    }

    public function clearActivity(): void
    {
        $this->validatedConfirmations = [];
    }
}
