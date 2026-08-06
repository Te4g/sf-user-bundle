<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class AccountSettingsTest extends AccountWebTestCase
{
    public function testProfileUpdatesTheNameWithoutChangingAnUnchangedEmail(): void
    {
        $user = $this->createUser('profile@example.com');
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', $this->url('app_account_profile'));
        $this->client->submit($crawler->filter('form[name="profile"]')->form([
            'profile[name]' => 'Updated Name',
            'profile[email]' => 'profile@example.com',
        ]));

        self::assertResponseRedirects($this->url('app_account'));
        self::assertEmailCount(0);
        $user = $this->refreshUser($user);
        self::assertSame('Updated Name', $user->getName());
        self::assertSame('profile@example.com', $user->getEmail());
        self::assertNull($user->getPendingEmail());
    }

    public function testPasswordChangeRejectsTheWrongCurrentPasswordAndClearsResetRequestsOnSuccess(): void
    {
        $user = $this->createUser('password@example.com', true, 'old-password');
        $resetHelper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        self::assertInstanceOf(ResetPasswordHelperInterface::class, $resetHelper);
        $resetHelper->generateResetToken($user);
        self::assertSame(1, $this->entityManager()->getRepository(ResetPasswordRequest::class)->count([]));
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $this->url('app_account_password'));
        $this->client->submit($crawler->filter('form[name="password_change"]')->form([
            'password_change[currentPassword]' => 'wrong-password',
            'password_change[plainPassword][first]' => 'new-password',
            'password_change[plainPassword][second]' => 'new-password',
        ]));
        self::assertResponseStatusCodeSame(422);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($this->refreshUser($user), 'old-password'));
        self::assertSame(1, $this->entityManager()->getRepository(ResetPasswordRequest::class)->count([]));

        $crawler = $this->client->request('GET', $this->url('app_account_password'));
        $this->client->submit($crawler->filter('form[name="password_change"]')->form([
            'password_change[currentPassword]' => 'old-password',
            'password_change[plainPassword][first]' => 'new-password',
            'password_change[plainPassword][second]' => 'new-password',
        ]));

        self::assertResponseRedirects($this->url('app_account'));
        self::assertTrue($hasher->isPasswordValid($this->refreshUser($user), 'new-password'));
        self::assertSame(0, $this->entityManager()->getRepository(ResetPasswordRequest::class)->count([]));
    }

    public function testPendingEmailStaysInactiveUntilSignedConfirmationAndThenClearsResetRequests(): void
    {
        $user = $this->createUser('old@example.com');
        $resetHelper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        self::assertInstanceOf(ResetPasswordHelperInterface::class, $resetHelper);
        $resetHelper->generateResetToken($user);
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', $this->url('app_account_profile'));
        $this->client->submit($crawler->filter('form[name="profile"]')->form([
            'profile[name]' => 'Pending User',
            'profile[email]' => 'New@Example.COM',
        ]));

        self::assertResponseRedirects($this->url('app_account'));
        self::assertEmailCount(1);
        $link = $this->emailLink();
        self::assertSame($this->url('app_account_email_verify'), parse_url($link, PHP_URL_PATH));
        $user = $this->refreshUser($user);
        self::assertSame('old@example.com', $user->getEmail());
        self::assertSame('new@example.com', $user->getPendingEmail());

        $this->client->request('GET', $link);

        self::assertResponseRedirects($this->url('app_account'));
        $user = $this->refreshUser($user);
        self::assertSame('new@example.com', $user->getEmail());
        self::assertNull($user->getPendingEmail());
        self::assertSame(0, $this->entityManager()->getRepository(ResetPasswordRequest::class)->count([]));
    }

    public function testEmailConfirmationRejectsStaleLinksAndRechecksUniqueness(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $this->url('app_account_profile'));
        $this->client->submit($crawler->filter('form[name="profile"]')->form([
            'profile[name]' => 'Owner',
            'profile[email]' => 'first@example.com',
        ]));
        $staleLink = $this->emailLink();

        $crawler = $this->client->request('GET', $this->url('app_account_profile'));
        $this->client->submit($crawler->filter('form[name="profile"]')->form([
            'profile[name]' => 'Owner',
            'profile[email]' => 'claimed@example.com',
        ]));
        $currentLink = $this->emailLink();
        $this->createUser('claimed@example.com');

        $this->client->request('GET', $staleLink);
        self::assertResponseStatusCodeSame(422);
        $user = $this->refreshUser($user);
        self::assertSame('owner@example.com', $user->getEmail());
        self::assertSame('claimed@example.com', $user->getPendingEmail());

        $this->client->request('GET', $currentLink);
        self::assertResponseStatusCodeSame(422);
        $user = $this->refreshUser($user);
        self::assertSame('owner@example.com', $user->getEmail());
        self::assertSame('claimed@example.com', $user->getPendingEmail());
        self::assertSame(2, $this->entityManager()->getRepository(User::class)->count([]));
    }

    public function testDeletionRequiresTheCurrentPasswordRemovesStateAndInvalidatesTheSession(): void
    {
        $user = $this->createUser('delete@example.com', true, 'delete-password');
        $resetHelper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        self::assertInstanceOf(ResetPasswordHelperInterface::class, $resetHelper);
        $resetHelper->generateResetToken($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $this->url('app_account_delete'));
        $this->client->submit($crawler->filter('form[name="delete_account"]')->form([
            'delete_account[currentPassword]' => 'wrong-password',
            'delete_account[confirm]' => '1',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertInstanceOf(User::class, $this->user('delete@example.com'));

        $crawler = $this->client->request('GET', $this->url('app_account_delete'));
        $this->client->submit($crawler->filter('form[name="delete_account"]')->form([
            'delete_account[currentPassword]' => 'delete-password',
            'delete_account[confirm]' => '1',
        ]));

        self::assertResponseRedirects($this->url('app_home'));
        self::assertNull($this->user('delete@example.com'));
        self::assertSame(0, $this->entityManager()->getRepository(ResetPasswordRequest::class)->count([]));
        $this->client->request('GET', $this->url('app_account'));
        self::assertResponseRedirects($this->url('app_login'));
    }
}
