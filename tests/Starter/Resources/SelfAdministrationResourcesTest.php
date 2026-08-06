<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SelfAdministrationResourcesTest extends TestCase
{
    public function testEmailChangeKeepsActiveAddressUntilConfirmationAndHandlesTheUniquenessRace(): void
    {
        $source = $this->resource('src/Account/ProfileService.php');

        self::assertStringContainsString('function requestEmailChange(', $source);
        self::assertStringContainsString('#[\SensitiveParameter] string $newEmail', $source);
        self::assertStringContainsString('setPendingEmail($normalized)', $source);
        self::assertStringContainsString("generateSignature(\n            'app_account_email_verify'", $source);
        self::assertStringContainsString('function confirmEmailChange(', $source);
        self::assertStringContainsString('findOneByNormalizedEmail($pendingEmail)', $source);
        self::assertStringContainsString('promotePendingEmail($userId, $pendingEmail)', $source);
        self::assertStringContainsString('catch (UniqueConstraintViolationException)', $source);

        $request = strstr($source, 'public function requestEmailChange');
        self::assertIsString($request);
        $request = strstr($request, 'public function confirmEmailChange', true);
        self::assertIsString($request);
        self::assertStringNotContainsString('setEmail(', $request);

        self::assertLessThan(
            strpos($source, 'promotePendingEmail($userId, $pendingEmail)'),
            strpos($source, 'findOneByNormalizedEmail($pendingEmail)'),
        );
    }

    public function testProfileServiceUpdatesTheNameAndEmailsThePendingAddress(): void
    {
        $service = $this->resource('src/Account/ProfileService.php');
        $mailer = $this->resource('src/Mailer/AccountMailer.php');

        self::assertStringContainsString('function updateName(User $user, string $name): void', $service);
        self::assertStringContainsString('setName(trim($name))', $service);
        self::assertStringContainsString('sendEmailChange($user, $signature->getSignedUrl())', $service);
        self::assertStringContainsString('function sendEmailChange(User $user, string $absoluteUrl): void', $mailer);
        self::assertStringContainsString('$user->getPendingEmail()', $mailer);
    }

    public function testEmailChangeUsesTheDedicatedNamedLimiter(): void
    {
        $limiter = $this->resource('src/Account/AccountRequestLimiter.php');
        $controller = $this->resource('src/Controller/ProfileController.php');

        self::assertStringContainsString("#[Autowire(service: 'limiter.account_email_change')]", $limiter);
        self::assertStringContainsString('function emailChange(Request $request, string $email): bool', $limiter);
        self::assertStringContainsString('$this->requestLimiter->emailChange($request, $input->email)', $controller);
    }

    public function testPasswordChangeRequiresTheCurrentPasswordAndInvalidatesResetRequests(): void
    {
        $source = $this->resource('src/Account/PasswordService.php');

        self::assertStringContainsString('function change(', $source);
        self::assertSame(2, substr_count($source, '#[\SensitiveParameter] string $'));
        self::assertStringContainsString('isPasswordValid($user, $currentPassword)', $source);
        self::assertStringContainsString('hashPassword($user, $newPassword)', $source);
        self::assertStringContainsString('wrapInTransaction(', $source);
        self::assertStringContainsString('save($user, false)', $source);
        self::assertStringContainsString('removeRequests($user)', $source);
    }

    public function testDeletionRequiresPasswordAndRemovesAccountStateInOneTransaction(): void
    {
        $service = $this->resource('src/Account/AccountDeletionService.php');
        $controller = $this->resource('src/Controller/DeleteAccountController.php');

        self::assertStringContainsString('#[\SensitiveParameter] string $currentPassword', $service);
        self::assertStringContainsString('isPasswordValid($user, $currentPassword)', $service);
        self::assertStringContainsString('wrapInTransaction(', $service);
        self::assertStringContainsString('removeRequests($user)', $service);
        self::assertStringContainsString('remove($user, false)', $service);

        $deletionCall = strpos($controller, '$this->deletion->delete(');
        self::assertIsInt($deletionCall);
        $failureCatch = strpos($controller, '} catch (\\RuntimeException) {', $deletionCall);
        $invalidation = strpos($controller, 'invalidate()');
        self::assertIsInt($failureCatch);
        self::assertIsInt($invalidation);
        self::assertLessThan($failureCatch, $deletionCall);
        self::assertLessThan($invalidation, $deletionCall);
        self::assertLessThan($invalidation, $failureCatch);
        self::assertStringContainsString("redirectToRoute('app_home')", $controller);
    }

    public function testFormsAreDtoBackedAndExplicitlyCsrfScoped(): void
    {
        foreach ([
            'ProfileType.php' => ['ProfileInput::class', "'csrf_token_id' => 'account_profile'"],
            'PasswordChangeType.php' => ['PasswordChangeInput::class', "'csrf_token_id' => 'account_password'"],
            'DeleteAccountType.php' => ['DeleteAccountInput::class', "'csrf_token_id' => 'account_delete'"],
        ] as $file => [$dataClass, $csrfTokenId]) {
            $source = $this->resource('src/Form/'.$file);
            self::assertStringContainsString("'data_class' => ".$dataClass, $source, $file);
            self::assertStringContainsString($csrfTokenId, $source, $file);
        }

        $profile = $this->resource('src/Form/Model/ProfileInput.php');
        self::assertStringContainsString('public string $name', $profile);
        self::assertStringContainsString('public string $email', $profile);
        self::assertStringNotContainsString('Password', $profile);

        foreach (['PasswordChangeInput.php', 'DeleteAccountInput.php'] as $file) {
            self::assertStringContainsString('public string $currentPassword', $this->resource('src/Form/Model/'.$file));
        }

        self::assertStringContainsString(
            'public string $plainPassword',
            $this->resource('src/Form/Model/PasswordChangeInput.php'),
        );
    }

    public function testControllersDeclareExactRoutesAndUseGenericUnprocessableFormErrors(): void
    {
        $controllers = implode("\n", array_map(
            fn (string $file): string => $this->resource('src/Controller/'.$file),
            ['AccountController.php', 'ProfileController.php', 'PasswordController.php', 'DeleteAccountController.php'],
        ));

        foreach ([
            'app_account',
            'app_account_profile',
            'app_account_password',
            'app_account_email_verify',
            'app_account_delete',
        ] as $route) {
            self::assertStringContainsString("name: '".$route."'", $controllers, $route);
        }

        self::assertStringContainsString('new FormError(', $controllers);
        self::assertStringContainsString('Response::HTTP_UNPROCESSABLE_ENTITY', $controllers);
        self::assertStringNotContainsString('LoggerInterface', $controllers);
        self::assertStringNotContainsString('$input->currentPassword.', $controllers);
        self::assertStringNotContainsString('$input->email.', $controllers);
        self::assertStringNotContainsString('Te4g', $controllers);
    }

    public function testEverySelfAdministrationPhpResourceLintsAndUsesOnlyApplicationDependencies(): void
    {
        foreach ($this->phpResources() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringNotContainsString('Te4g\\UserBundle', $source, $path);

            $lint = new Process([PHP_BINARY, '-l', $path]);
            $lint->run();

            self::assertTrue($lint->isSuccessful(), $lint->getErrorOutput().$lint->getOutput());
        }
    }

    private function resource(string $path): string
    {
        $fullPath = $this->applicationResources().'/'.$path;
        self::assertFileExists($fullPath, $path);

        $source = file_get_contents($fullPath);
        self::assertIsString($source, $path);

        return $source;
    }

    /** @return list<string> */
    private function phpResources(): array
    {
        $paths = glob($this->applicationResources().'/{src/Account,src/Mailer,src/Form,src/Form/Model,src/Controller}/*.php', GLOB_BRACE);
        self::assertIsArray($paths);
        sort($paths);

        return $paths;
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}
