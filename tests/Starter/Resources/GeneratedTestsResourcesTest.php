<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Process\Process;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Constraints\PasswordStrengthValidator;
use Symfony\Component\Yaml\Yaml;
use Te4g\Starter\Generation\ResourceCatalog;

final class GeneratedTestsResourcesTest extends TestCase
{
    public function testEveryApprovedWorkflowHasAGeneratedFunctionalTest(): void
    {
        $files = (new ResourceCatalog($this->applicationResources()))->files();

        foreach ([
            'EmailLinkExtractor.php',
            'ObservableTestLogger.php',
            'RegistrationVerificationTest.php',
            'AuthenticationTest.php',
            'PasswordResetTest.php',
            'AccountSettingsTest.php',
            'SecurityBoundaryTest.php',
        ] as $name) {
            self::assertArrayHasKey('tests/Functional/'.$name, $files);
        }

        self::assertArrayHasKey('config/packages/test/account_tests.yaml', $files);
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
        self::assertStringContainsString('ObservableTestLogger::clear()', $helper);
        self::assertStringContainsString('CacheItemPoolInterface::class', $helper);
        self::assertStringContainsString("get('cache.account_test_rate_limiter')", $helper);
        self::assertStringContainsString('->clear()', $helper);
    }

    public function testRenderedTemplatedEmailClearsContextAndTheExtractorReadsADecodedAbsoluteHref(): void
    {
        $extractor = $this->applicationResources().'/tests/Functional/EmailLinkExtractor.php';
        self::assertFileExists($extractor);
        require_once $extractor;

        $email = (new TemplatedEmail())
            ->htmlTemplate('email/verify.html.twig')
            ->context(['absolute_url' => 'https://localhost/verify/email?id=1&signature=a%2Bb'])
            ->html('<p><a href="https://localhost/verify/email?id=1&amp;signature=a%2Bb">Verify</a></p>')
        ;
        $email->markAsRendered();

        self::assertSame([], $email->getContext());
        $extractorClass = 'App\Tests\Functional\EmailLinkExtractor';
        self::assertTrue(class_exists($extractorClass));
        self::assertSame(
            'https://localhost/verify/email?id=1&signature=a%2Bb',
            (new \ReflectionMethod($extractorClass, 'from'))->invoke(null, $email, 'localhost', 'https'),
        );
    }

    public function testGeneratedSuccessAndExpiryPasswordsMeetTheInstalledMediumStrengthConstraint(): void
    {
        $settings = $this->methodSource(
            $this->resource('tests/Functional/AccountSettingsTest.php'),
            'testPasswordChangeRejectsTheWrongCurrentPasswordAndClearsResetRequestsOnSuccess',
        );
        $expiredReset = $this->methodSource(
            $this->resource('tests/Functional/PasswordResetTest.php'),
            'testExpiredResetLinksAreRejectedWithoutChangingThePassword',
        );
        $strongPassword = 'Correct Horse Battery Staple 42!';

        self::assertGreaterThanOrEqual(
            PasswordStrength::STRENGTH_MEDIUM,
            PasswordStrengthValidator::estimateStrength($strongPassword),
        );
        self::assertSame(5, substr_count($settings, "'".$strongPassword."'"));
        self::assertSame(2, substr_count($expiredReset, "'".$strongPassword."'"));
        self::assertStringNotContainsString("'new-password'", $settings);
        self::assertStringNotContainsString("'new-password'", $expiredReset);
    }

    public function testGeneratedTestInfrastructureUsesAnObservableLoggerAndEphemeralLimiterStorage(): void
    {
        $configuration = Yaml::parseFile($this->applicationResources().'/config/packages/test/account_tests.yaml');
        self::assertSame(
            'cache.adapter.filesystem',
            $configuration['framework']['cache']['pools']['cache.account_test_rate_limiter']['adapter'] ?? null,
        );

        foreach ([
            'account_login',
            'account_registration',
            'account_magic_link',
            'account_password_reset',
            'account_verification_resend',
            'account_email_change',
        ] as $limiter) {
            self::assertSame(
                'cache.account_test_rate_limiter',
                $configuration['framework']['rate_limiter'][$limiter]['cache_pool'] ?? null,
                $limiter,
            );
        }

        foreach (['logger', 'monolog.logger.request', 'monolog.logger.security'] as $loggerService) {
            self::assertSame(
                'App\Tests\Functional\ObservableTestLogger',
                $configuration['services'][$loggerService]['alias'] ?? null,
                $loggerService,
            );
        }
        foreach ([
            'App\\Tests\\Functional\\ObservableRequestTestLogger' => 'monolog.logger.request',
            'App\\Tests\\Functional\\ObservableSecurityTestLogger' => 'monolog.logger.security',
        ] as $decorator => $channel) {
            self::assertSame($channel, $configuration['services'][$decorator]['decorates'] ?? null, $decorator);
            self::assertSame('ignore', $configuration['services'][$decorator]['decoration_on_invalid'] ?? null, $decorator);
        }
        $logger = $this->applicationResources().'/tests/Functional/ObservableTestLogger.php';
        self::assertFileExists($logger);
        require_once $logger;
        $loggerClass = 'App\Tests\Functional\ObservableTestLogger';
        if (!class_exists($loggerClass)) {
            self::fail('The generated observable logger class was not loaded.');
        }
        $logger = (new \ReflectionClass(\App\Tests\Functional\ObservableTestLogger::class))->newInstance();
        (new \ReflectionMethod($loggerClass, 'clear'))->invoke(null);
        (new \ReflectionMethod($loggerClass, 'info'))->invoke(
            $logger,
            'application security probe',
            ['route' => 'app_login', 'password' => 'submitted-secret'],
        );
        self::assertSame(1, (new \ReflectionMethod($loggerClass, 'count'))->invoke(null));
        $contents = (new \ReflectionMethod($loggerClass, 'contents'))->invoke(null);
        self::assertIsString($contents);
        self::assertStringContainsString('application security probe', $contents);
        self::assertStringContainsString('app_login', $contents);
        self::assertStringContainsString('submitted-secret', $contents);
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
                'testResetFormRejectsInvalidCsrfWithoutConsumingTheToken',
                'testEveryConfiguredAccountLimiterAcceptsFiveAttemptsAndRejectsTheSixth',
                'testLimiterStateSurvivesKernelRebootsUntilThePoolIsExplicitlyCleared',
                'testMagicLinkEndpointLimiterAccumulatesAcrossKernelReboots',
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

        $resetCsrf = $this->methodSource(
            $this->resource('tests/Functional/SecurityBoundaryTest.php'),
            'testResetFormRejectsInvalidCsrfWithoutConsumingTheToken',
        );
        foreach ([
            'app_forgot_password_reset',
            "'reset_password' => [",
            "'_token' => 'invalid'",
            'ResetPasswordHelperInterface::class',
            "'Correct Horse Battery Staple 42!'",
            "'old-password'",
        ] as $required) {
            self::assertStringContainsString($required, $resetCsrf, $required);
        }

        $secretCoverage = $this->methodSource(
            $this->resource('tests/Functional/SecurityBoundaryTest.php'),
            'testSubmittedPasswordsAreAbsentFromResponsesAndApplicationLogs',
        );
        foreach ([
            'registrationSecret',
            'loginSecret',
            'currentPasswordSecret',
            'newPasswordSecret',
            'resetPasswordSecret',
            'deletionPasswordSecret',
            'ObservableTestLogger::count()',
        ] as $required) {
            self::assertStringContainsString($required, $secretCoverage, $required);
        }

        $limiterIsolation = $this->methodSource(
            $this->resource('tests/Functional/SecurityBoundaryTest.php'),
            'testLimiterStateSurvivesKernelRebootsUntilThePoolIsExplicitlyCleared',
        );
        foreach (['task-10-repeat-run-isolation', 'ensureKernelShutdown()', 'static::createClient()', 'CacheItemPoolInterface::class', '->clear()', 'assertFalse', 'assertTrue'] as $required) {
            self::assertStringContainsString($required, $limiterIsolation, $required);
        }

        $endpointLimiter = $this->methodSource(
            $this->resource('tests/Functional/SecurityBoundaryTest.php'),
            'testMagicLinkEndpointLimiterAccumulatesAcrossKernelReboots',
        );
        foreach (['app_login_link_request', 'endpoint-limit@example.com', 'assertEmailCount(1)', 'assertEmailCount(0)'] as $required) {
            self::assertStringContainsString($required, $endpointLimiter, $required);
        }
        self::assertStringNotContainsString('disableReboot()', $endpointLimiter);
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

    private function methodSource(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');
        self::assertIsInt($start, $method);
        $nextPublic = strpos($source, "\n    public function ", $start + 1);
        $nextPrivate = strpos($source, "\n    private function ", $start + 1);
        $ends = array_filter([$nextPublic, $nextPrivate], is_int(...));
        $end = [] === $ends ? strlen($source) : min($ends);

        return substr($source, $start, $end - $start);
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}
