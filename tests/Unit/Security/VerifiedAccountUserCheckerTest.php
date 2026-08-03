<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Te4g\UserBundle\Exception\UnverifiedAccount;
use Te4g\UserBundle\Security\VerifiedAccountUserChecker;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class VerifiedAccountUserCheckerTest extends TestCase
{
    public function testPreAuthIgnoresUsersOutsideTheAccountContract(): void
    {
        (new VerifiedAccountUserChecker())->checkPreAuth(new InMemoryUser('outside@example.com', 'hash'));

        $this->addToAssertionCount(1);
    }

    public function testPreAuthAllowsVerifiedAccount(): void
    {
        $user = new TestAccountUser();
        $user->setVerified(true);

        (new VerifiedAccountUserChecker())->checkPreAuth($user);

        $this->addToAssertionCount(1);
    }

    public function testPreAuthRejectsUnverifiedAccountWithGenericTranslationKey(): void
    {
        $user = new TestAccountUser();
        $user->setEmail('private@example.com');

        try {
            (new VerifiedAccountUserChecker())->checkPreAuth($user);
            self::fail('An unverified account must not authenticate.');
        } catch (CustomUserMessageAccountStatusException $exception) {
            self::assertInstanceOf(UnverifiedAccount::class, $exception);
            self::assertSame('te4g_user.security.account_unverified', $exception->getMessageKey());
            self::assertSame([], $exception->getMessageData());
            self::assertStringNotContainsString('private@example.com', $exception->getMessage());
            self::assertStringNotContainsString('private@example.com', $exception->getMessageKey());
        }
    }

    public function testPostAuthDoesNotRecheckUnverifiedAccount(): void
    {
        (new VerifiedAccountUserChecker())->checkPostAuth(new TestAccountUser());

        $this->addToAssertionCount(1);
    }
}
