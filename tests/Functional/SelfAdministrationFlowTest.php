<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Controller\AccountController;
use Te4g\UserBundle\Tests\Fixtures\App\TestResetPasswordRequestRepository;
use Te4g\UserBundle\Tests\Fixtures\App\TestVerifyEmailHelper;
use Te4g\UserBundle\Tests\Fixtures\App\WebTestKernel;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingEmailChangedListener;
use Te4g\UserBundle\Tests\Fixtures\Domain\SwitchableAccountDeletionGuard;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUser;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUserRepository;

#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class SelfAdministrationFlowTest extends BundleWebTestCase
{
    public function testAccountPagesRequireAuthentication(): void
    {
        $client = $this->browser();

        $client->request('GET', '/account');

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('http://localhost/login', $client->getResponse()->headers->get('Location'));
    }

    public function testNonAccountPrincipalIsRejected(): void
    {
        $outsideAccount = new InMemoryUser('outside-account-contract', null, ['ROLE_USER']);
        $this->tokenStorage()->setToken(new UsernamePasswordToken($outsideAccount, 'main', $outsideAccount->getRoles()));
        $options = self::getContainer()->get(UserBundleOptions::class);
        self::assertInstanceOf(UserBundleOptions::class, $options);
        $controller = new AccountController($options);
        $controller->setContainer(self::getContainer());

        $this->expectException(AccessDeniedException::class);

        $controller->index();
    }

    public function testExactAuthenticatedRouteMapIsAvailable(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $expected = [
            'te4g_user_account' => [['GET'], '/account'],
            'te4g_user_profile' => [['GET', 'POST'], '/account/profile'],
            'te4g_user_email_change_confirm' => [['GET'], '/account/email-change'],
            'te4g_user_password_change' => [['GET', 'POST'], '/account/password'],
            'te4g_user_account_delete' => [['POST'], '/account/delete'],
        ];

        foreach ($expected as $name => [$methods, $path]) {
            $route = $router->getRouteCollection()->get($name);
            self::assertNotNull($route, $name);
            self::assertSame($methods, $route->getMethods(), $name);
            self::assertSame($path, $route->getPath(), $name);
        }
    }

    public function testAccountOverviewSeparatesActiveAndPendingEmailsAndRendersDeletionForm(): void
    {
        [$client, $user] = $this->loginVerifiedUser('active@example.test');
        $user->setPendingEmail('pending@example.test');
        $this->repository()->save($user);

        $crawler = $client->request('GET', '/account');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('active@example.test', trim($crawler->filter('[data-account-active-email]')->text()));
        self::assertSame('pending@example.test', trim($crawler->filter('[data-account-pending-email]')->text()));
        self::assertCount(1, $crawler->filter('form[name="delete_account"][method="post"]'));
    }

    public function testProfileStartsFromPrincipalAndConfirmsPendingEmail(): void
    {
        [$client, $user] = $this->loginVerifiedUser('active@example.test');
        $crawler = $client->request('GET', '/account/profile');
        self::assertSame('active@example.test', $crawler->filter('#profile_email')->attr('value'));

        $client->submit($crawler->selectButton('Save profile')->form([
            'profile[email]' => ' New@Example.TEST ',
            'profile[currentPassword]' => 'Valid Current Password 42!',
        ]));

        self::assertSame('/account', $client->getResponse()->headers->get('Location'));
        $pendingUser = $this->repository()->findById((int) $user->getId());
        self::assertInstanceOf(TestUser::class, $pendingUser);
        self::assertSame('new@example.test', $pendingUser->getPendingEmail());
        self::assertSame('active@example.test', $pendingUser->getEmail());
        $mailer = self::getContainer()->get('test.'.AccountMailerInterface::class);
        self::assertInstanceOf(RecordingAccountMailer::class, $mailer);
        self::assertCount(1, $mailer->messages);
        self::assertSame('email_change', $mailer->messages[0]['type']);
        $signedUrl = $mailer->messages[0]['absoluteUrl'];

        $client->request('GET', $signedUrl);

        self::assertSame('/account', $client->getResponse()->headers->get('Location'));
        self::assertSame([[
            'signedUrl' => $signedUrl,
            'userId' => (string) $user->getId(),
            'userEmail' => 'new@example.test',
        ]], $this->verifyEmailHelper()->validatedConfirmations);
        $confirmedUser = $this->repository()->findById((int) $user->getId());
        self::assertInstanceOf(TestUser::class, $confirmedUser);
        self::assertSame('new@example.test', $confirmedUser->getEmail());
        self::assertNull($confirmedUser->getPendingEmail());
    }

    public function testEmailConfirmationRejectsAnotherAccountIdWithoutAnyWorkflowSideEffect(): void
    {
        [$client, $principal] = $this->loginVerifiedUser('principal@example.test');
        $otherAccount = $this->persistVerifiedUserWithContainer(self::getContainer(), 'other@example.test');
        $otherAccount->setPendingEmail('other-pending@example.test');
        $this->repository()->save($otherAccount);
        $this->clearConfirmationActivity();
        $mismatchedUrl = 'https://localhost/account/email-change?id='.$otherAccount->getId().'&signature=mismatch';

        $client->request('GET', $mismatchedUrl);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $reloadedPrincipal = $this->repository()->findById((int) $principal->getId());
        self::assertInstanceOf(TestUser::class, $reloadedPrincipal);
        self::assertSame('principal@example.test', $reloadedPrincipal->getEmail());
        self::assertNull($reloadedPrincipal->getPendingEmail());
        $reloadedOther = $this->repository()->findById((int) $otherAccount->getId());
        self::assertInstanceOf(TestUser::class, $reloadedOther);
        self::assertSame('other@example.test', $reloadedOther->getEmail());
        self::assertSame('other-pending@example.test', $reloadedOther->getPendingEmail());
        $this->assertNoConfirmationSideEffects();
    }

    public function testRejectedEmailConfirmationForCurrentAccountIsSafeAndForwardsExactUri(): void
    {
        [$client, $user] = $this->loginVerifiedUser('active@example.test');
        $user->setPendingEmail('pending@example.test');
        $this->repository()->save($user);
        $signedUrl = 'https://localhost/account/email-change?id='.$user->getId().'&signature=rejected-signature';
        $this->clearConfirmationActivity();
        $this->verifyEmailHelper()->rejectNextConfirmation();

        $client->request('GET', $signedUrl);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $reloaded = $this->repository()->findById((int) $user->getId());
        self::assertInstanceOf(TestUser::class, $reloaded);
        self::assertSame('active@example.test', $reloaded->getEmail());
        self::assertSame('pending@example.test', $reloaded->getPendingEmail());
        self::assertSame([[
            'signedUrl' => $signedUrl,
            'userId' => (string) $user->getId(),
            'userEmail' => 'pending@example.test',
        ]], $this->verifyEmailHelper()->validatedConfirmations);
        self::assertSame([], $this->repository()->savedUsers);
        self::assertSame([], $this->resetRequestRepository()->removedFor);
        self::assertSame([], $this->emailChangedListener()->events);
    }

    public function testProfileWrongPasswordReturnsUnprocessableWithoutChangingPendingEmail(): void
    {
        [$client, $user] = $this->loginVerifiedUser();
        $crawler = $client->request('GET', '/account/profile');

        $client->submit($crawler->selectButton('Save profile')->form([
            'profile[email]' => 'new@example.test',
            'profile[currentPassword]' => 'wrong-current-password',
        ]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertNull($user->getPendingEmail());
        self::assertStringNotContainsString('wrong-current-password', (string) $client->getResponse()->getContent());
    }

    public function testPasswordChangeRequiresCurrentPasswordAndUsesPostRedirectGetOnSuccess(): void
    {
        [$client, $user] = $this->loginVerifiedUser();
        $crawler = $client->request('GET', '/account/password');
        $client->submit($crawler->selectButton('Change password')->form([
            'password_change[currentPassword]' => 'wrong-current-password',
            'password_change[plainPassword][first]' => 'Replacement Password 43! Secure',
            'password_change[plainPassword][second]' => 'Replacement Password 43! Secure',
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertTrue($this->hasher()->isPasswordValid($user, 'Valid Current Password 42!'));

        $crawler = $client->request('GET', '/account/password');
        $client->submit($crawler->selectButton('Change password')->form([
            'password_change[currentPassword]' => 'Valid Current Password 42!',
            'password_change[plainPassword][first]' => 'Replacement Password 43! Secure',
            'password_change[plainPassword][second]' => 'Replacement Password 43! Secure',
        ]));

        self::assertSame('/account', $client->getResponse()->headers->get('Location'));
        $changedUser = $this->repository()->findById((int) $user->getId());
        self::assertInstanceOf(TestUser::class, $changedUser);
        self::assertTrue($this->hasher()->isPasswordValid($changedUser, 'Replacement Password 43! Secure'));
    }

    public function testInvalidDeletionCsrfDoesNotDeleteOrClearAuthentication(): void
    {
        [$client, $user] = $this->loginVerifiedUser();

        $client->request('POST', '/account/delete', ['delete_account' => [
            'currentPassword' => 'Valid Current Password 42!',
            'confirm' => '1',
            '_token' => 'invalid-csrf-token',
        ]]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->tokenStorage()->getToken());
        self::assertInstanceOf(TestUser::class, $this->repository()->findById((int) $user->getId()));
    }

    public function testDeletionRequiresBothCurrentPasswordAndExplicitConfirmation(): void
    {
        [$client, $user] = $this->loginVerifiedUser();
        $crawler = $client->request('GET', '/account');

        $client->submit($crawler->selectButton('Delete account')->form([
            'delete_account[currentPassword]' => 'wrong-current-password',
            'delete_account[confirm]' => '1',
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->tokenStorage()->getToken());
        self::assertInstanceOf(TestUser::class, $this->repository()->findById((int) $user->getId()));

        $crawler = $client->request('GET', '/account');
        $client->submit($crawler->selectButton('Delete account')->form([
            'delete_account[currentPassword]' => 'Valid Current Password 42!',
            'delete_account[confirm]' => false,
        ]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->tokenStorage()->getToken());
        self::assertInstanceOf(TestUser::class, $this->repository()->findById((int) $user->getId()));
    }

    public function testDeleteSuccessClearsAuthenticationAndInvalidatesSessionOnlyAfterPersistence(): void
    {
        [$client, $user] = $this->loginVerifiedUser();
        $crawler = $client->request('GET', '/account');
        $session = $client->getRequest()->getSession();
        $session->set('survives-until-delete-success', 'present');
        $session->save();
        $sessionIdBeforeDeletion = $session->getId();
        self::assertNotSame('', $sessionIdBeforeDeletion);

        $client->submit($crawler->selectButton('Delete account')->form([
            'delete_account[currentPassword]' => 'Valid Current Password 42!',
            'delete_account[confirm]' => '1',
        ]));

        self::assertSame('/login', $client->getResponse()->headers->get('Location'));
        self::assertNull($this->tokenStorage()->getToken());
        $postDeletionSession = $client->getRequest()->getSession();
        self::assertFalse($postDeletionSession->has('survives-until-delete-success'));
        self::assertNotSame($sessionIdBeforeDeletion, $postDeletionSession->getId());
        self::assertNull($this->repository()->findById((int) $user->getId()));

        $client->request('GET', '/account');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('http://localhost/login', $client->getResponse()->headers->get('Location'));
    }

    public function testRepositoryDeleteFailureKeepsAuthenticationAndSession(): void
    {
        [$client, $user] = $this->loginVerifiedUser();
        $crawler = $client->request('GET', '/account');
        $session = $client->getRequest()->getSession();
        $session->set('must-survive-delete-failure', 'present');
        $session->save();
        $this->repository()->failNextRemove();

        $client->submit($crawler->selectButton('Delete account')->form([
            'delete_account[currentPassword]' => 'Valid Current Password 42!',
            'delete_account[confirm]' => '1',
        ]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->tokenStorage()->getToken());
        self::assertSame('present', $session->get('must-survive-delete-failure'));
        self::assertInstanceOf(TestUser::class, $this->repository()->findById((int) $user->getId()));
    }

    public function testGuardDeleteFailureKeepsAuthenticationAndSession(): void
    {
        [$client, $user] = $this->loginVerifiedUser();
        $crawler = $client->request('GET', '/account');
        $session = $client->getRequest()->getSession();
        $session->set('must-survive-guard-failure', 'present');
        $session->save();
        $guard = self::getContainer()->get(SwitchableAccountDeletionGuard::class);
        self::assertInstanceOf(SwitchableAccountDeletionGuard::class, $guard);
        $guard->rejectNextDeletion();

        $client->submit($crawler->selectButton('Delete account')->form([
            'delete_account[currentPassword]' => 'Valid Current Password 42!',
            'delete_account[confirm]' => '1',
        ]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->tokenStorage()->getToken());
        self::assertSame('present', $session->get('must-survive-guard-failure'));
        self::assertInstanceOf(TestUser::class, $this->repository()->findById((int) $user->getId()));
    }

    public function testDeletionEndpointIsNotFoundAndFormIsHiddenWhenFeatureIsDisabled(): void
    {
        $kernel = new WebTestKernel('deletion_disabled', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(TestContainer::class, $container);
            $entityManager = $container->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
            $user = $this->persistVerifiedUserWithContainer($container, 'disabled@example.test');
            $client = new KernelBrowser($kernel);
            $client->disableReboot();
            $client->loginUser($user);

            $crawler = $client->request('GET', '/account');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertCount(0, $crawler->filter('form[name="delete_account"]'));

            $client->request('POST', '/account/delete', ['delete_account' => [
                'currentPassword' => 'Valid Current Password 42!',
                'confirm' => '1',
            ]]);
            self::assertSame(404, $client->getResponse()->getStatusCode());
            $tokenStorage = $container->get(TokenStorageInterface::class);
            self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
            self::assertNotNull($tokenStorage->getToken());
        } finally {
            $kernel->shutdown();
            $kernel->cleanup();
        }
    }

    /** @return array{KernelBrowser, TestUser} */
    private function loginVerifiedUser(string $email = 'user@example.test'): array
    {
        $user = $this->persistVerifiedUserWithContainer(self::getContainer(), $email);
        $client = $this->browser();
        $client->disableReboot();
        $client->loginUser($user);

        return [$client, $user];
    }

    private function persistVerifiedUserWithContainer(ContainerInterface $container, string $email): TestUser
    {
        $user = new TestUser();
        $user->setEmail($email);
        $user->setVerified(true);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user->setPassword($hasher->hashPassword($user, 'Valid Current Password 42!'));
        $repository = $container->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);
        $repository->save($user);

        return $user;
    }

    private function browser(): KernelBrowser
    {
        $kernel = self::$kernel;

        if (null === $kernel) {
            throw new \LogicException('The functional test kernel is not booted.');
        }

        return new KernelBrowser($kernel);
    }

    private function repository(): TestUserRepository
    {
        $repository = self::getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);

        return $repository;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }

    private function tokenStorage(): TokenStorageInterface
    {
        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);

        return $tokenStorage;
    }

    private function verifyEmailHelper(): TestVerifyEmailHelper
    {
        $helper = self::getContainer()->get(TestVerifyEmailHelper::class);
        self::assertInstanceOf(TestVerifyEmailHelper::class, $helper);

        return $helper;
    }

    private function resetRequestRepository(): TestResetPasswordRequestRepository
    {
        $repository = self::getContainer()->get(TestResetPasswordRequestRepository::class);
        self::assertInstanceOf(TestResetPasswordRequestRepository::class, $repository);

        return $repository;
    }

    private function emailChangedListener(): RecordingEmailChangedListener
    {
        $listener = self::getContainer()->get(RecordingEmailChangedListener::class);
        self::assertInstanceOf(RecordingEmailChangedListener::class, $listener);

        return $listener;
    }

    private function clearConfirmationActivity(): void
    {
        $this->repository()->clearActivity();
        $this->verifyEmailHelper()->clearActivity();
        $this->resetRequestRepository()->clearActivity();
        $this->emailChangedListener()->clearActivity();
    }

    private function assertNoConfirmationSideEffects(): void
    {
        self::assertSame([], $this->verifyEmailHelper()->validatedConfirmations);
        self::assertSame([], $this->repository()->savedUsers);
        self::assertSame([], $this->resetRequestRepository()->removedFor);
        self::assertSame([], $this->emailChangedListener()->events);
    }
}
