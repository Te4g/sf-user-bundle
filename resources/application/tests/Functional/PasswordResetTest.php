<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ResetPasswordRequest;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetTest extends AccountWebTestCase
{
    public function testUnknownResetRequestsAreNeutralAndKnownRequestsAreThrottled(): void
    {
        $crawler = $this->client->request('GET', $this->url('app_forgot_password_request'));
        $this->client->submit($crawler->filter('form[name="form"]')->form([
            'form[email]' => 'missing@example.com',
        ]));
        self::assertResponseRedirects($this->url('app_forgot_password_check_email'));
        self::assertEmailCount(0);

        $this->createUser('reset@example.com');
        $crawler = $this->client->request('GET', $this->url('app_forgot_password_request'));
        $this->client->submit($crawler->filter('form[name="form"]')->form([
            'form[email]' => 'reset@example.com',
        ]));
        self::assertResponseRedirects($this->url('app_forgot_password_check_email'));
        self::assertEmailCount(1);

        $crawler = $this->client->request('GET', $this->url('app_forgot_password_request'));
        $this->client->submit($crawler->filter('form[name="form"]')->form([
            'form[email]' => 'RESET@EXAMPLE.COM',
        ]));
        self::assertResponseRedirects($this->url('app_forgot_password_check_email'));
        self::assertEmailCount(0);
        self::assertSame(1, $this->entityManager()->getRepository(ResetPasswordRequest::class)->count([]));
    }

    public function testExpiredResetLinksAreRejectedWithoutChangingThePassword(): void
    {
        $user = $this->createUser('expired@example.com', true, 'old-password');
        $crawler = $this->client->request('GET', $this->url('app_forgot_password_request'));
        $this->client->submit($crawler->filter('form[name="form"]')->form([
            'form[email]' => 'expired@example.com',
        ]));
        $link = $this->emailLink();
        self::assertSame($this->url('app_forgot_password_reset', ['token' => basename(parse_url($link, PHP_URL_PATH) ?: '')]), parse_url($link, PHP_URL_PATH));

        $this->entityManager()->createQuery(
            'UPDATE '.ResetPasswordRequest::class.' request SET request.expiresAt = :expired',
        )->setParameter('expired', new \DateTimeImmutable('-1 minute'))->execute();
        $this->entityManager()->clear();

        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->filter('form[name="reset_password"]')->form([
            'reset_password[plainPassword][first]' => 'Correct Horse Battery Staple 42!',
            'reset_password[plainPassword][second]' => 'Correct Horse Battery Staple 42!',
        ]));

        self::assertResponseStatusCodeSame(422);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($this->refreshUser($user), 'old-password'));
    }

    public function testValidResetChangesThePasswordAndRemovesEveryOutstandingRequest(): void
    {
        $user = $this->createUser('success@example.com', true, 'old-password');
        $crawler = $this->client->request('GET', $this->url('app_forgot_password_request'));
        $this->client->submit($crawler->filter('form[name="form"]')->form([
            'form[email]' => 'success@example.com',
        ]));
        $link = $this->emailLink();
        self::assertSame($this->url('app_forgot_password_reset', ['token' => basename(parse_url($link, PHP_URL_PATH) ?: '')]), parse_url($link, PHP_URL_PATH));

        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->filter('form[name="reset_password"]')->form([
            'reset_password[plainPassword][first]' => 'new-secure-password',
            'reset_password[plainPassword][second]' => 'new-secure-password',
        ]));

        self::assertResponseRedirects($this->url('app_login'));
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($this->refreshUser($user), 'new-secure-password'));
        self::assertSame(0, $this->entityManager()->getRepository(ResetPasswordRequest::class)->count([]));
    }
}
