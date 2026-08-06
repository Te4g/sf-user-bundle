<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Te4g\Starter\Generation\ResourceCatalog;

final class GeneratedTestsResourcesTest extends TestCase
{
    public function testEveryApprovedWorkflowHasAGeneratedFunctionalTest(): void
    {
        $files = (new ResourceCatalog($this->applicationResources()))->files();

        foreach ([
            'RegistrationVerificationTest.php',
            'AuthenticationTest.php',
            'PasswordResetTest.php',
            'AccountSettingsTest.php',
            'SecurityBoundaryTest.php',
        ] as $name) {
            self::assertArrayHasKey('tests/Functional/'.$name, $files);
        }
    }

    public function testEveryGeneratedFunctionalTestResourceParsesAndLints(): void
    {
        foreach ($this->generatedTestPaths() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source, $path);
            self::assertNotEmpty(token_get_all($source, TOKEN_PARSE), $path);

            $lint = new Process([PHP_BINARY, '-l', $path]);
            $lint->run();

            self::assertTrue($lint->isSuccessful(), $lint->getErrorOutput().$lint->getOutput());
        }
    }

    public function testGeneratedHelperOwnsIsolationAndCreatesRealisticPersistedUsers(): void
    {
        $helper = $this->resource('tests/Functional/AccountWebTestCase.php');

        self::assertStringContainsString('function createUser(', $helper);
        self::assertStringContainsString("string \$email,\n        bool \$verified = true,\n        string \$password = 'password',", $helper);
        self::assertStringContainsString('UserPasswordHasherInterface::class', $helper);
        self::assertStringContainsString('hashPassword($user, $password)', $helper);
        self::assertStringContainsString('->setVerified($verified)', $helper);
        self::assertStringContainsString('persist($user)', $helper);
        self::assertStringContainsString('flush()', $helper);
        self::assertStringContainsString("createQuery('DELETE FROM '.ResetPasswordRequest::class)", $helper);
        self::assertStringContainsString("createQuery('DELETE FROM '.User::class)", $helper);
    }

    public function testGeneratedSuitesSubstantivelyCoverTheApprovedAccountLifecycle(): void
    {
        foreach ([
            'RegistrationVerificationTest.php' => [
                'testSuccessfulRegistrationSendsASignedLinkAndVerificationUnlocksTheDashboard',
                'testRegistrationDoesNotDuplicateANormalizedEmailAddress',
                'testUnverifiedAccountsAreDeniedTheDashboardButCanUseTheVerificationAllowlist',
            ],
            'AuthenticationTest.php' => [
                'testPasswordLoginAndCsrfProtectedLogoutManageTheSession',
                'testUnknownMagicLinkRequestsRemainNeutralAndSendNoEmail',
                'testValidMagicLinkConfirmsIntentAuthenticatesAndVerifiesTheAccount',
                'testInvalidExpiredAndAlreadyConsumedMagicLinksAreRejected',
            ],
            'PasswordResetTest.php' => [
                'testUnknownResetRequestsAreNeutralAndKnownRequestsAreThrottled',
                'testExpiredResetLinksAreRejectedWithoutChangingThePassword',
                'testValidResetChangesThePasswordAndRemovesEveryOutstandingRequest',
            ],
            'AccountSettingsTest.php' => [
                'testProfileUpdatesTheNameWithoutChangingAnUnchangedEmail',
                'testPasswordChangeRejectsTheWrongCurrentPasswordAndClearsResetRequestsOnSuccess',
                'testPendingEmailStaysInactiveUntilSignedConfirmationAndThenClearsResetRequests',
                'testEmailConfirmationRejectsStaleLinksAndRechecksUniqueness',
                'testDeletionRequiresTheCurrentPasswordRemovesStateAndInvalidatesTheSession',
            ],
            'SecurityBoundaryTest.php' => [
                'testEveryStateChangingFormRejectsAnInvalidCsrfToken',
                'testEveryConfiguredAccountLimiterAcceptsFiveAttemptsAndRejectsTheSixth',
                'testEmailAddressSubmissionEndpointsHaveNeutralKnownAndUnknownResponses',
                'testSubmittedPasswordsAreAbsentFromResponsesAndApplicationLogs',
            ],
        ] as $file => $expectedMethods) {
            $methods = $this->methodNames($this->resource('tests/Functional/'.$file));
            foreach ($expectedMethods as $method) {
                self::assertContains($method, $methods, $file);
            }
        }

        $suite = implode("\n", array_map(
            fn (string $path): string => $this->resource('tests/Functional/'.$path),
            [
                'RegistrationVerificationTest.php',
                'AuthenticationTest.php',
                'PasswordResetTest.php',
                'AccountSettingsTest.php',
                'SecurityBoundaryTest.php',
            ],
        ));

        foreach ([
            'app_register',
            'app_check_email',
            'app_verify_email',
            'app_verify_resend',
            'app_login',
            'app_login_link_request',
            'app_login_link_check',
            'app_logout',
            'app_forgot_password_request',
            'app_forgot_password_check_email',
            'app_forgot_password_reset',
            'app_dashboard',
            'app_account',
            'app_account_profile',
            'app_account_password',
            'app_account_email_verify',
            'app_account_delete',
            'app_home',
        ] as $route) {
            self::assertStringContainsString("'".$route."'", $suite, $route);
        }

        foreach ([
            'registration[name]',
            'registration[email]',
            'registration[plainPassword][first]',
            '_username',
            '_password',
            'form[email]',
            'reset_password[plainPassword][first]',
            'profile[name]',
            'profile[email]',
            'password_change[currentPassword]',
            'password_change[plainPassword][first]',
            'delete_account[currentPassword]',
            'delete_account[confirm]',
            '_csrf_token',
        ] as $field) {
            self::assertStringContainsString($field, $suite, $field);
        }

        foreach ([
            'account_login',
            'account_registration',
            'account_magic_link',
            'account_password_reset',
            'account_verification_resend',
            'account_email_change',
        ] as $limiter) {
            self::assertStringContainsString("'".$limiter."'", $suite, $limiter);
        }

        self::assertStringContainsString('applicationLogs()', $suite);
        self::assertStringContainsString('assertStringNotContainsString($secret, $logs)', $suite);
    }

    /** @return list<string> */
    private function generatedTestPaths(): array
    {
        $paths = glob($this->applicationResources().'/tests/Functional/*.php');
        self::assertIsArray($paths);
        sort($paths);

        return $paths;
    }

    private function resource(string $path): string
    {
        $source = file_get_contents($this->applicationResources().'/'.$path);
        self::assertIsString($source, $path);

        return $source;
    }

    /** @return list<string> */
    private function methodNames(string $source): array
    {
        $methods = [];
        $expectName = false;
        foreach (token_get_all($source, TOKEN_PARSE) as $token) {
            if (is_array($token) && T_FUNCTION === $token[0]) {
                $expectName = true;
                continue;
            }

            if ($expectName && is_array($token) && T_STRING === $token[0]) {
                $methods[] = $token[1];
                $expectName = false;
            }
        }

        return $methods;
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}
