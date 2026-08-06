<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationVerificationTest extends AccountWebTestCase
{
    public function testSuccessfulRegistrationSendsASignedLinkAndVerificationUnlocksTheDashboard(): void
    {
        $crawler = $this->client->request('GET', $this->url('app_register'));
        $form = $crawler->filter('form[name="registration"]')->form([
            'registration[name]' => 'Alice Example',
            'registration[email]' => 'Alice@Example.COM',
            'registration[plainPassword][first]' => 'correct horse battery staple',
            'registration[plainPassword][second]' => 'correct horse battery staple',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects($this->url('app_check_email'));
        self::assertEmailCount(1);
        $user = $this->user('alice@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Alice Example', $user->getName());
        self::assertFalse($user->isVerified());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($user, 'correct horse battery staple'));

        $verificationLink = $this->emailLink();
        self::assertSame($this->url('app_verify_email'), parse_url($verificationLink, PHP_URL_PATH));
        $this->client->request('GET', $verificationLink);

        self::assertResponseRedirects($this->url('app_dashboard'));
        self::assertTrue($this->refreshUser($user)->isVerified());
    }

    public function testRegistrationDoesNotDuplicateANormalizedEmailAddress(): void
    {
        $existing = $this->createUser('existing@example.com');
        $crawler = $this->client->request('GET', $this->url('app_register'));
        $form = $crawler->filter('form[name="registration"]')->form([
            'registration[name]' => 'Another Person',
            'registration[email]' => 'EXISTING@EXAMPLE.COM',
            'registration[plainPassword][first]' => 'replacement-password',
            'registration[plainPassword][second]' => 'replacement-password',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects($this->url('app_check_email'));
        self::assertEmailCount(0);
        self::assertSame(1, $this->entityManager()->getRepository(User::class)->count([]));
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($this->refreshUser($existing), 'password'));
    }

    public function testUnverifiedAccountsAreDeniedTheDashboardButCanUseTheVerificationAllowlist(): void
    {
        $user = $this->createUser('waiting@example.com', false);
        $this->client->loginUser($user);

        $this->client->request('GET', $this->url('app_dashboard'));
        self::assertResponseRedirects($this->url('app_check_email'));

        $crawler = $this->client->request('GET', $this->url('app_check_email'));
        self::assertResponseIsSuccessful();
        $resend = $crawler->filter('form[action="'.$this->url('app_verify_resend').'"]')->form([
            'form[email]' => 'waiting@example.com',
        ]);
        $this->client->submit($resend);
        self::assertResponseRedirects($this->url('app_check_email'));
        self::assertEmailCount(1);

        $this->client->request('GET', $this->url('app_login_link_check'), [
            'expires' => '1',
            'user' => 'waiting@example.com',
            'hash' => 'invalid',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->url('app_verify_email'), ['id' => '0']);
        self::assertResponseRedirects($this->url('app_login'));

        $this->client->request('POST', $this->url('app_logout'), [
            '_csrf_token' => $this->csrfToken('logout'),
        ]);
        self::assertResponseRedirects($this->url('app_home'));
    }
}
