<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUser;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUserRepository;

final class PublicAccountFlowTest extends BundleWebTestCase
{
    public function testPublicRegistrationRouteRendersItsForm(): void
    {
        $client = $this->browser();

        $crawler = $client->request('GET', '/register');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('form[name="registration"]'));
        self::assertCount(1, $crawler->filter('[aria-live="polite"]'));
    }

    public function testInvalidRegistrationReturnsUnprocessableEntity(): void
    {
        $client = $this->browser();

        $crawler = $client->request('POST', '/register', ['registration' => [
            'email' => 'not-an-email',
            'plainPassword' => ['first' => 'short', 'second' => 'different'],
        ]]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[role="alert"]'));
    }

    public function testExactPublicRouteMapIsAvailable(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $expected = [
            'te4g_user_register' => [['GET', 'POST'], '/register'],
            'te4g_user_verify_email' => [['GET'], '/verify-email'],
            'te4g_user_verify_resend' => [['POST'], '/verify-email/resend'],
            'te4g_user_login' => [['GET', 'POST'], '/login'],
            'te4g_user_login_link_request' => [['POST'], '/login-link'],
            'te4g_user_logout' => [['POST'], '/logout'],
            'te4g_user_login_link_check' => [['GET', 'POST'], '/login-link/check'],
            'te4g_user_password_request' => [['GET', 'POST'], '/forgot-password'],
            'te4g_user_password_reset' => [['GET', 'POST'], '/reset-password/{token}'],
        ];

        foreach ($expected as $name => [$methods, $path]) {
            $route = $router->getRouteCollection()->get($name);
            self::assertNotNull($route, $name);
            self::assertSame($methods, $route->getMethods(), $name);
            self::assertSame($path, $route->getPath(), $name);
        }
    }

    public function testRegistrationVerificationAndResendFlowUsesNeutralRedirects(): void
    {
        $client = $this->browser();
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration[email]' => ' User@Example.TEST ',
            'registration[plainPassword][first]' => 'Correct Horse Battery Staple 42!',
            'registration[plainPassword][second]' => 'Correct Horse Battery Staple 42!',
        ]);

        $client->submit($form);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/check-email', $client->getResponse()->headers->get('Location'));
        $repository = self::getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);
        $user = $repository->findOneByEmail('user@example.test');
        self::assertInstanceOf(TestUser::class, $user);
        self::assertFalse($user->isVerified());
        $mailer = self::getContainer()->get('test.'.AccountMailerInterface::class);
        self::assertInstanceOf(RecordingAccountMailer::class, $mailer);
        self::assertCount(1, $mailer->messages);
        self::assertSame('verification', $mailer->messages[0]['type']);

        self::assertSame(
            ['status' => 302, 'location' => '/check-email'],
            $this->submitEmailForm($client, '/check-email', 'Resend verification email', 'USER@example.test'),
        );
        $mailer = self::getContainer()->get('test.'.AccountMailerInterface::class);
        self::assertInstanceOf(RecordingAccountMailer::class, $mailer);
        self::assertCount(1, $mailer->messages);
        self::assertSame('verification', $mailer->messages[0]['type']);

        $verificationUrl = $mailer->messages[0]['absoluteUrl'];
        $client->request('GET', (string) parse_url($verificationUrl, PHP_URL_PATH).'?'.parse_url($verificationUrl, PHP_URL_QUERY));
        self::assertSame(
            '/login',
            $client->getResponse()->headers->get('Location'),
            (string) $client->getResponse()->getContent(),
        );
        $verifiedUser = $repository->findOneByEmail('user@example.test');
        self::assertInstanceOf(TestUser::class, $verifiedUser);
        self::assertTrue($verifiedUser->isVerified());
    }

    public function testMagicLinkUnknownAndKnownRequestsHaveTheSameExternalResponse(): void
    {
        $client = $this->browser();
        $unknownResponse = $this->submitEmailForm($client, '/login', 'Send sign-in link', 'missing@example.test');

        $repository = self::getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);
        $user = new TestUser();
        $user->setEmail('known@example.test');
        $user->setVerified(true);
        $repository->save($user);

        $knownResponse = $this->submitEmailForm($client, '/login', 'Send sign-in link', 'known@example.test');

        self::assertSame(302, $unknownResponse['status']);
        self::assertSame($unknownResponse, $knownResponse);
        $mailer = self::getContainer()->get('test.'.AccountMailerInterface::class);
        self::assertInstanceOf(RecordingAccountMailer::class, $mailer);
        self::assertCount(1, $mailer->messages);
        self::assertSame('magic_link', $mailer->messages[0]['type']);
    }

    public function testMagicLinkCheckCopiesOnlySignedParametersBeforePostingToSameRoute(): void
    {
        $client = $this->browser();
        $crawler = $client->request('GET', '/login-link/check?expires=2000000000&user=user%40example.test&hash=signed&evil=leak');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('form[action="/login-link/check"][method="post"]'));
        self::assertSame('2000000000', $crawler->filter('input[name="expires"]')->attr('value'));
        self::assertSame('user@example.test', $crawler->filter('input[name="user"]')->attr('value'));
        self::assertSame('signed', $crawler->filter('input[name="hash"]')->attr('value'));
        self::assertCount(0, $crawler->filter('input[name="evil"]'));

        $client->submit($crawler->selectButton('Continue')->form());
        self::assertSame(
            '/login',
            $client->getResponse()->headers->get('Location'),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testForgotPasswordIsNeutralAndResetTokenIsOneTimeUse(): void
    {
        $client = $this->browser();
        $client->disableReboot();
        $unknownResponse = $this->submitEmailForm($client, '/forgot-password', 'Send reset link', 'missing@example.test');
        $repository = self::getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);
        $user = new TestUser();
        $user->setEmail('known@example.test');
        $repository->save($user);

        $knownResponse = $this->submitEmailForm($client, '/forgot-password', 'Send reset link', 'known@example.test');

        self::assertSame($unknownResponse, $knownResponse);
        $mailer = self::getContainer()->get('test.'.AccountMailerInterface::class);
        self::assertInstanceOf(RecordingAccountMailer::class, $mailer);
        self::assertCount(1, $mailer->messages);
        $resetUrl = $mailer->messages[0]['absoluteUrl'];
        $resetPath = (string) parse_url($resetUrl, PHP_URL_PATH);
        $crawler = $client->request('GET', $resetPath);
        $form = $crawler->selectButton('Save password')->form([
            'reset_password[plainPassword][first]' => 'Another Correct Horse Battery Staple 43!',
            'reset_password[plainPassword][second]' => 'Another Correct Horse Battery Staple 43!',
        ]);
        $client->submit($form);
        self::assertSame(
            '/login',
            $client->getResponse()->headers->get('Location'),
            (string) $client->getResponse()->getContent(),
        );
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $resetUser = $repository->findOneByEmail('known@example.test');
        self::assertInstanceOf(TestUser::class, $resetUser);
        self::assertTrue($hasher->isPasswordValid($resetUser, 'Another Correct Horse Battery Staple 43!'));

        $crawler = $client->request('GET', $resetPath);
        $client->submit($crawler->selectButton('Save password')->form([
            'reset_password[plainPassword][first]' => 'Yet Another Correct Password 44!',
            'reset_password[plainPassword][second]' => 'Yet Another Correct Password 44!',
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('invalid or has expired', (string) $client->getResponse()->getContent());
    }

    public function testRejectedRateLimitDoesNotInvokeRegistrationManager(): void
    {
        $email = 'limited@example.test';
        $this->exhaustLimiter($email);
        $client = $this->browser();
        $crawler = $client->request('GET', '/register');
        $client->submit($crawler->selectButton('Register')->form([
            'registration[email]' => $email,
            'registration[plainPassword][first]' => 'Correct Horse Battery Staple 42!',
            'registration[plainPassword][second]' => 'Correct Horse Battery Staple 42!',
        ]));

        self::assertSame('/check-email', $client->getResponse()->headers->get('Location'));
        $repository = self::getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);
        self::assertNull($repository->findOneByEmail($email));
        $mailer = self::getContainer()->get('test.'.AccountMailerInterface::class);
        self::assertInstanceOf(RecordingAccountMailer::class, $mailer);
        self::assertSame([], $mailer->messages);
    }

    public function testRejectedRateLimitSkipsEveryEmailRequestManagerWithNeutralResponses(): void
    {
        $repository = self::getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);
        $unverified = new TestUser();
        $unverified->setEmail('resend-limited@example.test');
        $repository->save($unverified);
        $verified = new TestUser();
        $verified->setEmail('magic-limited@example.test');
        $verified->setVerified(true);
        $repository->save($verified);
        $reset = new TestUser();
        $reset->setEmail('reset-limited@example.test');
        $repository->save($reset);

        $client = $this->browser();
        foreach ([
            ['/check-email', 'Resend verification email', 'resend-limited@example.test'],
            ['/login', 'Send sign-in link', 'magic-limited@example.test'],
            ['/forgot-password', 'Send reset link', 'reset-limited@example.test'],
        ] as [$path, $button, $email]) {
            $this->exhaustLimiter($email);
            $response = $this->submitEmailForm($client, $path, $button, $email);
            self::assertSame(['status' => 302, 'location' => '/check-email'], $response);
        }

        $mailer = self::getContainer()->get('test.'.AccountMailerInterface::class);
        self::assertInstanceOf(RecordingAccountMailer::class, $mailer);
        self::assertSame([], $mailer->messages);
    }

    public function testLoginFailureUsesGenericCopyOnly(): void
    {
        $client = $this->browser();
        $client->request('GET', '/login');
        $session = $client->getRequest()->getSession();
        $session->set(
            SecurityRequestAttributes::AUTHENTICATION_ERROR,
            new BadCredentialsException('private provider failure'),
        );
        $session->save();

        $client->request('GET', '/login');

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Unable to sign in with those credentials.', $content);
        self::assertStringNotContainsString('private provider failure', $content);
    }

    /** @return array{status: int, location: ?string} */
    private function submitEmailForm(KernelBrowser $client, string $path, string $button, string $email): array
    {
        $crawler = $client->request('GET', $path);
        $client->submit($crawler->selectButton($button)->form(['email[email]' => $email]));

        return [
            'status' => $client->getResponse()->getStatusCode(),
            'location' => $client->getResponse()->headers->get('Location'),
        ];
    }

    private function exhaustLimiter(string $email): void
    {
        $factory = self::getContainer()->get('limiter.te4g_user_account_requests');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $key = '127.0.0.1:'.hash('sha256', strtolower(trim($email)));
        $factory->create($key)->consume(20);
    }

    private function browser(): KernelBrowser
    {
        $kernel = self::$kernel;

        if (null === $kernel) {
            throw new \LogicException('The functional test kernel is not booted.');
        }

        return new KernelBrowser($kernel);
    }
}
