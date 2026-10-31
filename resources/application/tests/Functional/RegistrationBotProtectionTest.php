<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Account\AccountRequestLimiter;
use App\Account\RegistrationChallenge;
use App\Entity\User;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class RegistrationBotProtectionTest extends AccountWebTestCase
{
    public function testDirectPostWithoutAChallengeCannotCreateAnAccountOrSendMail(): void
    {
        $http = $this->enableChallenge();
        $this->submitRegistration(null);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager()->getRepository(User::class)->count([]));
        self::assertEmailCount(0);
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testAnArrayTokenIsRejectedWithoutCallingTheProvider(): void
    {
        $http = $this->enableChallenge();
        $this->submitRegistration(['forged']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager()->getRepository(User::class)->count([]));
        self::assertEmailCount(0);
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testRejectedTokenCannotResendAnExistingUnverifiedAccountsEmail(): void
    {
        $this->createUser('waiting-bot@example.com', false);
        $http = $this->enableChallenge(new MockResponse('{"success":false}'));
        $this->submitRegistration('rejected-token', 'waiting-bot@example.com');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->entityManager()->getRepository(User::class)->count([]));
        self::assertEmailCount(0);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testSuccessfulChallengePreservesSignupAndSignedEmailVerification(): void
    {
        $http = $this->enableChallenge(new MockResponse('{"success":true,"hostname":"localhost","action":"register"}'));
        $this->submitRegistration('valid-token');
        self::assertResponseRedirects($this->url('app_check_email'));
        self::assertEmailCount(1);
        $user = $this->user('challenge@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isVerified());
        $link = $this->emailLink();
        $this->client->request('GET', $link);
        self::assertTrue($this->refreshUser($user)->isVerified());
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testMissingProviderConfigurationFailsClosed(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set(RegistrationChallenge::class, new RegistrationChallenge(new MockHttpClient(), true, null, null, null, 'prod'));
        $this->submitRegistration('fake-token');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager()->getRepository(User::class)->count([]));
        self::assertEmailCount(0);
    }

    public function testChangingEmailAddressesDoesNotResetTheIpLimit(): void
    {
        $limiter = static::getContainer()->get(AccountRequestLimiter::class);
        self::assertInstanceOf(AccountRequestLimiter::class, $limiter);
        $request = Request::create('/register', server: ['REMOTE_ADDR' => '192.0.2.11']);
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($limiter->registration($request, 'person'.$i.'@example.com'));
        }
        self::assertFalse($limiter->registration($request, 'different-address@example.com'));
        $other = Request::create('/register', server: ['REMOTE_ADDR' => '192.0.2.12']);
        self::assertTrue($limiter->registration($other, 'different-address@example.com'));
    }

    private function enableChallenge(?MockResponse $response = null): MockHttpClient
    {
        $this->client->disableReboot();
        $http = new MockHttpClient($response);
        static::getContainer()->set(RegistrationChallenge::class, new RegistrationChallenge($http, true, 'site-key', 'secret-key', 'localhost', 'test'));

        return $http;
    }

    private function submitRegistration(mixed $token, string $email = 'challenge@example.com'): void
    {
        $crawler = $this->client->request('GET', $this->url('app_register'));
        $form = $crawler->filter('form[name="registration"]')->form([
            'registration[name]' => 'Legitimate member',
            'registration[email]' => $email,
            'registration[plainPassword][first]' => 'strong-enough-password',
            'registration[plainPassword][second]' => 'strong-enough-password',
        ]);
        $values = $form->getPhpValues();
        $values['registration']['botChallenge'] = $token;
        $this->client->request('POST', $form->getUri(), $values);
    }
}
