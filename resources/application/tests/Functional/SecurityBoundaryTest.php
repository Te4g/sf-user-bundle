<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class SecurityBoundaryTest extends AccountWebTestCase
{
    public function testEveryStateChangingFormRejectsAnInvalidCsrfToken(): void
    {
        $this->client->request('POST', $this->url('app_register'), [
            'registration' => [
                'name' => 'Invalid Csrf',
                'email' => 'register-csrf@example.com',
                'plainPassword' => ['first' => 'password', 'second' => 'password'],
                '_token' => 'invalid',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->user('register-csrf@example.com'));

        foreach ([
            'app_login_link_request',
            'app_forgot_password_request',
            'app_verify_resend',
        ] as $route) {
            $this->client->request('POST', $this->url($route), [
                'form' => ['email' => 'csrf@example.com', '_token' => 'invalid'],
            ]);
            self::assertResponseStatusCodeSame(422, $route);
        }

        $this->client->request('POST', $this->url('app_login'), [
            '_username' => 'csrf@example.com',
            '_password' => 'password',
            '_csrf_token' => 'invalid',
        ]);
        self::assertResponseRedirects($this->url('app_login'));
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', $this->url('app_login_link_check'), [
            '_csrf_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(403);

        $user = $this->createUser('csrf-account@example.com');
        $this->client->loginUser($user);
        foreach ([
            'app_account_profile' => [
                'profile' => ['name' => 'No Change', 'email' => 'csrf-account@example.com', '_token' => 'invalid'],
            ],
            'app_account_password' => [
                'password_change' => [
                    'currentPassword' => 'password',
                    'plainPassword' => ['first' => 'new-password', 'second' => 'new-password'],
                    '_token' => 'invalid',
                ],
            ],
            'app_account_delete' => [
                'delete_account' => ['currentPassword' => 'password', 'confirm' => '1', '_token' => 'invalid'],
            ],
        ] as $route => $parameters) {
            $this->client->request('POST', $this->url($route), $parameters);
            self::assertResponseStatusCodeSame(422, $route);
        }

        $this->client->request('POST', $this->url('app_logout'), ['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEveryConfiguredAccountLimiterAcceptsFiveAttemptsAndRejectsTheSixth(): void
    {
        foreach ([
            'account_login',
            'account_registration',
            'account_magic_link',
            'account_password_reset',
            'account_verification_resend',
            'account_email_change',
        ] as $name) {
            $factory = static::getContainer()->get('limiter.'.$name);
            self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
            $limiter = $factory->create(self::class.'::'.$name.'::'.bin2hex(random_bytes(8)));

            for ($attempt = 1; $attempt <= 5; ++$attempt) {
                self::assertTrue($limiter->consume()->isAccepted(), $name.' attempt '.$attempt);
            }

            self::assertFalse($limiter->consume()->isAccepted(), $name.' attempt 6');
        }
    }

    public function testEmailAddressSubmissionEndpointsHaveNeutralKnownAndUnknownResponses(): void
    {
        $this->createUser('known@example.com');

        $known = $this->submitEmailForm('app_login', 'app_login_link_request', 'known@example.com');
        self::assertEmailCount(1);
        $unknown = $this->submitEmailForm('app_login', 'app_login_link_request', 'missing-magic@example.com');
        self::assertEmailCount(0);
        self::assertSame($known, $unknown);

        $known = $this->submitEmailForm('app_forgot_password_request', 'app_forgot_password_request', 'known@example.com');
        self::assertEmailCount(1);
        $unknown = $this->submitEmailForm('app_forgot_password_request', 'app_forgot_password_request', 'missing-reset@example.com');
        self::assertEmailCount(0);
        self::assertSame($known, $unknown);

        $duplicate = $this->submitRegistration('KNOWN@EXAMPLE.COM');
        self::assertEmailCount(0);
        $created = $this->submitRegistration('new@example.com');
        self::assertEmailCount(1);
        self::assertSame($duplicate, $created);

        $waiting = $this->createUser('waiting-resend@example.com', false);
        $this->client->loginUser($waiting);
        $known = $this->submitEmailForm('app_check_email', 'app_verify_resend', 'waiting-resend@example.com');
        self::assertEmailCount(1);
        $unknown = $this->submitEmailForm('app_check_email', 'app_verify_resend', 'missing-resend@example.com');
        self::assertEmailCount(0);
        self::assertSame($known, $unknown);
    }

    public function testSubmittedPasswordsAreAbsentFromResponsesAndApplicationLogs(): void
    {
        $registrationSecret = 'registration-secret-'.bin2hex(random_bytes(8));
        $crawler = $this->client->request('GET', $this->url('app_register'));
        $this->client->submit($crawler->filter('form[name="registration"]')->form([
            'registration[name]' => 'Secret Test',
            'registration[email]' => 'secret-registration@example.com',
            'registration[plainPassword][first]' => $registrationSecret,
            'registration[plainPassword][second]' => 'different-password',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString($registrationSecret, (string) $this->client->getResponse()->getContent());

        $loginSecret = 'login-secret-'.bin2hex(random_bytes(8));
        $user = $this->createUser('secret-login@example.com');
        $crawler = $this->client->request('GET', $this->url('app_login'));
        $this->client->submit($crawler->filter('form[action="'.$this->url('app_login').'"]')->form([
            '_username' => 'secret-login@example.com',
            '_password' => $loginSecret,
        ]));
        self::assertResponseRedirects($this->url('app_login'));
        $this->client->followRedirect();
        self::assertStringNotContainsString($loginSecret, (string) $this->client->getResponse()->getContent());

        $currentPasswordSecret = 'current-secret-'.bin2hex(random_bytes(8));
        $newPasswordSecret = 'new-secret-'.bin2hex(random_bytes(8));
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', $this->url('app_account_password'));
        $this->client->submit($crawler->filter('form[name="password_change"]')->form([
            'password_change[currentPassword]' => $currentPasswordSecret,
            'password_change[plainPassword][first]' => $newPasswordSecret,
            'password_change[plainPassword][second]' => $newPasswordSecret,
        ]));
        self::assertResponseStatusCodeSame(422);
        $response = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($currentPasswordSecret, $response);
        self::assertStringNotContainsString($newPasswordSecret, $response);

        $logs = $this->applicationLogs();
        foreach ([$registrationSecret, $loginSecret, $currentPasswordSecret, $newPasswordSecret] as $secret) {
            self::assertStringNotContainsString($secret, $logs);
        }
    }

    /** @return array{int, string|null, string} */
    private function submitEmailForm(string $pageRoute, string $actionRoute, string $email): array
    {
        $crawler = $this->client->request('GET', $this->url($pageRoute));
        $form = $crawler->filter('form[action="'.$this->url($actionRoute).'"]')->form([
            'form[email]' => $email,
        ]);
        $this->client->submit($form);

        return $this->responseFingerprint();
    }

    /** @return array{int, string|null, string} */
    private function submitRegistration(string $email): array
    {
        $crawler = $this->client->request('GET', $this->url('app_register'));
        $this->client->submit($crawler->filter('form[name="registration"]')->form([
            'registration[name]' => 'Neutral Response',
            'registration[email]' => $email,
            'registration[plainPassword][first]' => 'neutral-password',
            'registration[plainPassword][second]' => 'neutral-password',
        ]));

        return $this->responseFingerprint();
    }

    /** @return array{int, string|null, string} */
    private function responseFingerprint(): array
    {
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), $response->headers->get('Location'), (string) $response->getContent()];
    }
}
