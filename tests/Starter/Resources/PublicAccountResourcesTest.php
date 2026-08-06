<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PublicAccountResourcesTest extends TestCase
{
    public function testRegistrationReturnsOnlyANewUserForControllerLogin(): void
    {
        $service = $this->resource('src/Account/RegistrationService.php');
        $controller = $this->resource('src/Controller/RegistrationController.php');

        self::assertStringContainsString('function register(string $name, string $email,', $service);
        self::assertStringContainsString(': ?User', $service);
        self::assertStringContainsString('findOneByNormalizedEmail($normalized)', $service);
        self::assertStringContainsString('UniqueConstraintViolationException', $service);
        self::assertStringContainsString('return null;', $service);
        self::assertStringContainsString("login(\$user, 'form_login', 'main')", $controller);
        self::assertStringContainsString("redirectToRoute('app_check_email')", $controller);
    }

    public function testVerificationUsesTheSignedEmailHelperAndExactRoutes(): void
    {
        $service = $this->resource('src/Account/EmailVerificationService.php');
        $controller = $this->resource('src/Controller/VerificationController.php');

        self::assertStringContainsString('VerifyEmailHelperInterface', $service);
        self::assertStringContainsString("generateSignature(\n            'app_verify_email'", $service);
        self::assertStringContainsString('validateEmailConfirmationFromRequest($request', $service);
        self::assertStringContainsString('$this->verification->verify($request,', $controller);
        self::assertStringContainsString("name: 'app_check_email'", $controller);
        self::assertStringContainsString("name: 'app_verify_email'", $controller);
        self::assertStringContainsString("name: 'app_verify_resend'", $controller);
        self::assertStringContainsString('verificationResend($request, $email)', $controller);
    }

    public function testCheckEmailOnlyOffersVerificationResendToAuthenticatedUnverifiedUsers(): void
    {
        $controller = $this->resource('src/Controller/VerificationController.php');
        $template = $this->resource('templates/security/check_email.html.twig');

        self::assertStringContainsString('$user = $this->getUser();', $controller);
        self::assertStringContainsString('$user instanceof User && !$user->isVerified()', $controller);
        self::assertStringContainsString("\$context['resend_form']", $controller);
        self::assertStringContainsString("path('app_login')", $template);
        self::assertStringContainsString('{% if resend_form is defined %}', $template);
    }

    public function testMagicLinkDelegatesAuthenticationAndVerifiesTheDestination(): void
    {
        $service = $this->resource('src/Account/MagicLinkService.php');
        $handler = $this->resource('src/Security/MagicLinkAuthenticationSuccessHandler.php');
        $security = $this->resource('config/packages/security.yaml');

        self::assertStringContainsString('LoginLinkHandlerInterface', $service);
        self::assertStringContainsString('createLoginLink($user)', $service);
        self::assertStringContainsString('AuthenticationSuccessHandlerInterface', $handler);
        self::assertStringContainsString('if (!$user instanceof User)', $handler);
        self::assertStringContainsString('setVerified(true)', $handler);
        self::assertStringContainsString("generate('app_dashboard')", $handler);
        self::assertStringContainsString('check_post_only: true', $security);
        self::assertStringContainsString('max_uses: 1', $security);
        self::assertStringContainsString('used_link_cache: cache.account_login_links', $security);
    }

    public function testPostedMagicLinkConfirmationRequiresRouteScopedCsrf(): void
    {
        $source = $this->resource('src/Security/LoginLinkCsrfSubscriber.php');

        self::assertStringContainsString("'app_login_link_check'", $source);
        self::assertStringContainsString('Request::METHOD_POST', $source);
        self::assertStringContainsString("new CsrfToken('app_login_link_check', \$submittedToken)", $source);
        self::assertStringContainsString('AccessDeniedHttpException', $source);
    }

    public function testPasswordResetUsesTheBundleHelperAndRemovesOutstandingRequests(): void
    {
        $service = $this->resource('src/Account/PasswordResetService.php');

        self::assertStringContainsString('ResetPasswordHelperInterface', $service);
        self::assertStringContainsString('generateResetToken($user)', $service);
        self::assertStringContainsString('validateTokenAndFetchUser($token)', $service);
        self::assertStringContainsString('hashPassword($user, $plainPassword)', $service);
        self::assertStringContainsString('wrapInTransaction(', $service);
        self::assertStringContainsString('save($user, false)', $service);
        self::assertStringContainsString('removeResetRequest($token)', $service);
        self::assertStringContainsString('removeRequests($user)', $service);
        self::assertStringContainsString('$user->setPassword($currentHash)', $service);
    }

    public function testPlainPasswordsAreSensitiveServiceParametersAndStayInFormModels(): void
    {
        foreach (['RegistrationService.php', 'PasswordResetService.php'] as $file) {
            self::assertStringContainsString(
                '#[\SensitiveParameter] string $plainPassword',
                $this->resource('src/Account/'.$file),
            );
        }

        self::assertStringContainsString(
            'public string $plainPassword',
            $this->resource('src/Form/Model/RegistrationInput.php'),
        );
        self::assertStringContainsString(
            'public string $plainPassword',
            $this->resource('src/Form/Model/ResetPasswordInput.php'),
        );

        $registrationType = $this->resource('src/Form/RegistrationType.php');
        self::assertStringContainsString("->add('name', TextType::class", $registrationType);
        self::assertStringContainsString("->add('email', EmailType::class", $registrationType);
        self::assertStringContainsString("->add('plainPassword', RepeatedType::class", $registrationType);
        self::assertStringContainsString("'data_class' => RegistrationInput::class", $registrationType);

        $resetType = $this->resource('src/Form/ResetPasswordType.php');
        self::assertStringContainsString("->add('plainPassword', RepeatedType::class", $resetType);
        self::assertStringContainsString("'data_class' => ResetPasswordInput::class", $resetType);
    }

    public function testMailerUsesEditableApplicationDefaultsAndApplicationTemplates(): void
    {
        $source = $this->resource('src/Mailer/AccountMailer.php');

        self::assertStringContainsString("private string \$fromEmail = 'no-reply@example.com'", $source);
        self::assertStringContainsString("private string \$fromName = 'Application'", $source);
        self::assertStringContainsString("'email/verify.html.twig'", $source);
        self::assertStringContainsString("'email/magic_link.html.twig'", $source);
        self::assertStringContainsString("'email/password_reset.html.twig'", $source);
        self::assertStringContainsString('new TemplatedEmail()', $source);
    }

    public function testControllersDeclareEveryPublicAccountRouteAndInvalidFormStatus(): void
    {
        $controllers = implode("\n", array_map(
            fn (string $file): string => $this->resource('src/Controller/'.$file),
            [
                'RegistrationController.php',
                'VerificationController.php',
                'LoginController.php',
                'MagicLinkController.php',
                'PasswordResetController.php',
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
        ] as $route) {
            self::assertStringContainsString("name: '".$route."'", $controllers, $route);
        }

        self::assertStringContainsString('Response::HTTP_UNPROCESSABLE_ENTITY', $controllers);
        self::assertStringNotContainsString('Te4g', $controllers);
    }

    public function testEmailSubmissionFormsRejectBlankAndMalformedAddresses(): void
    {
        foreach (['LoginController.php', 'MagicLinkController.php', 'PasswordResetController.php', 'VerificationController.php'] as $file) {
            $source = $this->resource('src/Controller/'.$file);

            self::assertStringContainsString('new Assert\NotBlank()', $source, $file);
            self::assertStringContainsString('new Assert\Email()', $source, $file);
        }
    }

    public function testAccountRequestLimiterUsesOnlyNamedNonLoginFactories(): void
    {
        $source = $this->resource('src/Account/AccountRequestLimiter.php');

        foreach ([
            'limiter.account_registration',
            'limiter.account_magic_link',
            'limiter.account_password_reset',
            'limiter.account_verification_resend',
        ] as $service) {
            self::assertStringContainsString($service, $source);
        }

        self::assertStringNotContainsString('limiter.account_login', $source);
        self::assertStringContainsString('normalize($email)', $source);
    }

    public function testEveryPublicWorkflowPhpResourceLintsAndHasNoBundleRuntimeReference(): void
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
        $source = file_get_contents($this->applicationResources().'/'.$path);
        self::assertIsString($source, $path);

        return $source;
    }

    /** @return list<string> */
    private function phpResources(): array
    {
        $paths = glob($this->applicationResources().'/{src/Account,src/Mailer,src/Security,src/Form,src/Form/Model,src/Controller}/*.php', GLOB_BRACE);
        self::assertIsArray($paths);
        sort($paths);

        return $paths;
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}
