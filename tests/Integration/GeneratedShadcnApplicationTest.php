<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Te4g\UserBundle\Form\DeleteAccountType;
use Te4g\UserBundle\Form\EmailType;
use Te4g\UserBundle\Form\Model\DeleteAccountInput;
use Te4g\UserBundle\Form\Model\EmailInput;
use Te4g\UserBundle\Form\Model\PasswordChangeInput;
use Te4g\UserBundle\Form\Model\ProfileInput;
use Te4g\UserBundle\Form\Model\RegistrationInput;
use Te4g\UserBundle\Form\Model\ResetPasswordInput;
use Te4g\UserBundle\Form\PasswordChangeType;
use Te4g\UserBundle\Form\ProfileType;
use Te4g\UserBundle\Form\RegistrationType;
use Te4g\UserBundle\Form\ResetPasswordType;
use Te4g\UserBundle\Installer\CssFragmentMerger;
use Te4g\UserBundle\Installer\ProjectLayout;
use Te4g\UserBundle\Installer\SkeletonRenderer;
use Te4g\UserBundle\Tests\Fixtures\App\GeneratedShadcnKernel;
use Te4g\UserBundle\Tests\Fixtures\App\PinnedTailwindBinary;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;
use Twig\Environment;

final class GeneratedShadcnApplicationTest extends TestCase
{
    private string $projectDirectory;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir().'/te4g-generated-shadcn-'.bin2hex(random_bytes(8));
        mkdir($this->projectDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDirectory);
    }

    public function testGeneratedFixtureRejectsMissingExplicitTailwindEnvironment(): void
    {
        $previous = getenv('TE4G_TAILWIND_BINARY');
        putenv('TE4G_TAILWIND_BINARY');
        try {
            $failure = null;
            try {
                new GeneratedShadcnKernel($this->projectDirectory, $this->tailwindBinary());
            } catch (\InvalidArgumentException $exception) {
                $failure = $exception;
            }
        } finally {
            if (is_string($previous)) {
                putenv('TE4G_TAILWIND_BINARY='.$previous);
            } else {
                putenv('TE4G_TAILWIND_BINARY');
            }
        }

        self::assertInstanceOf(\InvalidArgumentException::class, $failure);
        self::assertStringContainsString('TE4G_TAILWIND_BINARY', $failure->getMessage());
    }

    public function testGeneratedKernelRejectsSymlinkedTailwindBinary(): void
    {
        $target = $this->writeExecutable('tailwind-target', '≈ tailwindcss v4.3.3');
        $link = $this->projectDirectory.'/tailwind-link';
        symlink($target, $link);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('symlink');

        new GeneratedShadcnKernel($this->projectDirectory, $link);
    }

    public function testGeneratedKernelRejectsWrongTailwindVersion(): void
    {
        $binary = $this->writeExecutable('tailwind-wrong-version', '≈ tailwindcss v4.3.2');
        $sha256 = hash_file('sha256', $binary);
        self::assertIsString($sha256);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('4.3.3');

        new GeneratedShadcnKernel($this->projectDirectory, $binary, new PinnedTailwindBinary('4.3.3', $sha256));
    }

    public function testPinnedTailwindValidationAcceptsAnsiDecoratedVersionOutput(): void
    {
        $binary = $this->writeExecutable('tailwind-ansi-version', "\033[3m\033[1m\033[34m≈\033[39m\033[22m\033[23m tailwindcss \033[34mv4.3.3\033[39m");
        $sha256 = hash_file('sha256', $binary);
        self::assertIsString($sha256);

        self::assertSame($binary, (new PinnedTailwindBinary('4.3.3', $sha256))->validate($binary));
    }

    public function testGeneratedKernelRejectsWrongTailwindHash(): void
    {
        $binary = $this->writeExecutable('tailwind-wrong-hash', '≈ tailwindcss v4.3.3');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SHA-256');

        new GeneratedShadcnKernel($this->projectDirectory, $binary);
    }

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testGeneratedApplicationBuildsOfflineAndRendersAccessibleAssetBackedForms(): void
    {
        $this->installGeneratedApplication();
        $kernel = new GeneratedShadcnKernel($this->projectDirectory, $this->tailwindBinary());
        $kernel->boot();

        try {
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $this->assertCommandSucceeds($application, 'lint:twig', ['filename' => [$this->projectDirectory.'/templates']]);
            $tailwindOutput = $this->assertCommandSucceeds($application, 'tailwind:build');
            $plainTailwindOutput = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $tailwindOutput);
            self::assertIsString($plainTailwindOutput);
            self::assertStringContainsString('tailwindcss v4.3.3', $plainTailwindOutput);
            $iconsOutput = $this->assertCommandSucceeds($application, 'ux:icons:lock');
            self::assertStringContainsString('Imported 0 icons.', $iconsOutput);

            $builtCss = $this->projectDirectory.'/var/tailwind/app.built.css';
            self::assertFileExists($builtCss);
            $css = file_get_contents($builtCss);
            self::assertIsString($css);
            self::assertStringContainsString('--background:', $css);
            self::assertStringContainsString('.bg-background', $css);

            $container = $kernel->getContainer();
            $requestStack = $container->get('test.request_stack');
            $forms = $container->get('test.form_factory');
            $twig = $container->get('test.twig');
            self::assertInstanceOf(RequestStack::class, $requestStack);
            self::assertInstanceOf(FormFactoryInterface::class, $forms);
            self::assertInstanceOf(Environment::class, $twig);

            $request = Request::create('/');
            $request->setSession(new Session(new MockArraySessionStorage()));
            $requestStack->push($request);

            foreach ($this->renderedPages($twig, $forms) as $name => $html) {
                $document = new \DOMDocument();
                self::assertTrue(@$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING), $name);
                $xpath = new \DOMXPath($document);

                self::assertSame(1, $this->query($xpath, '//link[@rel="stylesheet"]')->length, $name.' stylesheet');
                self::assertSame(1, $this->query($xpath, '//script[@type="importmap"]')->length, $name.' importmap');
                self::assertSame(1, $this->query($xpath, '//script[@type="module"]')->length, $name.' module entrypoint');
                self::assertStringContainsString('@hotwired/stimulus', $html, $name);
                self::assertStringContainsString('@hotwired/turbo', $html, $name);
                self::assertSame(0, $this->query($xpath, '//label//label')->length, $name.' nested labels');

                $ids = [];
                foreach ($this->query($xpath, '//input[not(@type="hidden") and not(@type="submit") and not(@type="button")] | //textarea | //select') as $control) {
                    self::assertInstanceOf(\DOMElement::class, $control);
                    $id = $control->getAttribute('id');
                    self::assertNotSame('', $id, $name.' control id');
                    self::assertArrayNotHasKey($id, $ids, $name.' duplicate control id '.$id);
                    $ids[$id] = true;
                    $labels = $this->query($xpath, '//label[@for='.$this->xpathLiteral($id).']');
                    self::assertSame(1, $labels->length, $name.' label for '.$id);
                    $label = $labels->item(0);
                    self::assertInstanceOf(\DOMElement::class, $label);
                    self::assertNotSame('', trim($label->textContent), $name.' label text '.$id);

                    foreach (preg_split('/\s+/', trim($control->getAttribute('aria-describedby')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $descriptionId) {
                        self::assertSame(1, $this->query($xpath, '//*[@id='.$this->xpathLiteral($descriptionId).']')->length, $name.' description '.$descriptionId);
                    }
                }

                foreach ($this->query($xpath, '//*[@id]') as $node) {
                    self::assertInstanceOf(\DOMElement::class, $node);
                    $id = $node->getAttribute('id');
                    self::assertSame(1, $this->query($xpath, '//*[@id='.$this->xpathLiteral($id).']')->length, $name.' globally unique id '.$id);
                }
            }
        } finally {
            $kernel->shutdown();
            restore_exception_handler();
        }
    }

    private function installGeneratedApplication(): void
    {
        file_put_contents($this->projectDirectory.'/composer.json', '{"autoload":{"psr-4":{"GeneratedApp\\\\":"src/"}}}');
        $files = (new SkeletonRenderer())->renderAll(ProjectLayout::at($this->projectDirectory));
        foreach ($files as $path => $contents) {
            $this->write($path, $contents);
        }

        $applicationCss = "@import \"tailwindcss\";\n\n.application-owned { display: block; }\n";
        $this->write('assets/styles/app.css', (new CssFragmentMerger())->merge(
            $applicationCss,
            $files['assets/styles/te4g_user.css.fragment'],
        ));
        $this->write('assets/app.js', <<<'JS'
            import { Application } from '@hotwired/stimulus';
            import '@hotwired/turbo';
            import PasswordVisibilityController from './controllers/password_visibility_controller.js';
            import AlertDialogController from './controllers/alert_dialog_controller.js';

            const application = Application.start();
            application.register('password-visibility', PasswordVisibilityController);
            application.register('alert-dialog', AlertDialogController);
            JS);
        $this->write('assets/controllers.json', "{\"controllers\":[],\"entrypoints\":[]}\n");
        $this->write('assets/vendor/hotwired/stimulus.js', "export class Controller {}\nexport class Application { static start() { return new Application(); } register() {} }\n");
        $this->write('assets/vendor/hotwired/turbo.js', "export const Turbo = {};\n");
        $this->write('assets/vendor/shadcn/dist/tailwind.css', "@theme inline { --shadcn-fixture: 1; }\n");
        $this->write('assets/vendor/tw-animate-css/dist/tw-animate.css', ".animate-in { animation-duration: 100ms; }\n");
        $this->write('importmap.php', <<<'PHP'
            <?php

            return [
                'app' => ['path' => './assets/app.js', 'entrypoint' => true],
                '@hotwired/stimulus' => ['path' => './assets/vendor/hotwired/stimulus.js'],
                '@hotwired/turbo' => ['path' => './assets/vendor/hotwired/turbo.js'],
            ];
            PHP);
    }

    /** @return array<string, string> */
    private function renderedPages(Environment $twig, FormFactoryInterface $forms): array
    {
        $account = new TestAccountUser();
        $registration = $forms->create(RegistrationType::class, new RegistrationInput());
        $registration->submit(['email' => 'invalid', 'plainPassword' => ['first' => 'short', 'second' => 'short']]);
        $magic = $forms->create(EmailType::class, new EmailInput());
        $magic->submit(['email' => 'invalid']);
        $resend = $forms->create(EmailType::class, new EmailInput());
        $resend->submit(['email' => 'invalid']);
        $request = $forms->create(EmailType::class, new EmailInput());
        $request->submit(['email' => 'invalid']);
        $reset = $forms->create(ResetPasswordType::class, new ResetPasswordInput());
        $reset->submit(['plainPassword' => ['first' => 'short', 'second' => 'short']]);
        $profile = $forms->create(ProfileType::class, ProfileInput::fromAccount($account));
        $profile->submit(['email' => 'invalid', 'currentPassword' => 'wrong']);
        $password = $forms->create(PasswordChangeType::class, new PasswordChangeInput());
        $password->submit(['currentPassword' => 'wrong', 'plainPassword' => ['first' => 'short', 'second' => 'short']]);
        $deletion = $forms->create(DeleteAccountType::class, new DeleteAccountInput());
        $deletion->submit(['currentPassword' => 'wrong', 'confirm' => false]);

        return [
            'registration' => $twig->render('bundles/Te4gUserBundle/registration/register.html.twig', ['form' => $registration->createView()]),
            'login' => $twig->render('bundles/Te4gUserBundle/security/login.html.twig', ['authentication_failed' => false, 'last_username' => '', 'magic_link_form' => $magic->createView()]),
            'check email' => $twig->render('bundles/Te4gUserBundle/security/check_email.html.twig', ['resend_form' => $resend->createView()]),
            'login link' => $twig->render('bundles/Te4gUserBundle/security/login_link_check.html.twig', ['login_link_parameters' => []]),
            'password request' => $twig->render('bundles/Te4gUserBundle/password/request.html.twig', ['form' => $request->createView()]),
            'password reset' => $twig->render('bundles/Te4gUserBundle/password/reset.html.twig', ['form' => $reset->createView(), 'reset_failed' => false]),
            'profile' => $twig->render('bundles/Te4gUserBundle/account/profile.html.twig', ['account' => $account, 'form' => $profile->createView(), 'email_change_failed' => false]),
            'password change' => $twig->render('bundles/Te4gUserBundle/account/password.html.twig', ['form' => $password->createView(), 'password_change_failed' => false]),
            'account' => $twig->render('bundles/Te4gUserBundle/account/index.html.twig', ['account' => $account, 'deletion_form' => $deletion->createView(), 'deletion_failed' => false, 'alert_dialog_trigger_attrs' => []]),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function assertCommandSucceeds(Application $application, string $command, array $arguments = []): string
    {
        $tester = new CommandTester($application->find($command));
        $status = $tester->execute($arguments, ['interactive' => false, 'capture_stderr_separately' => false]);
        $output = $tester->getDisplay(true);
        self::assertSame(0, $status, $command." failed:\n".$output);

        return $output;
    }

    /** @return \DOMNodeList<\DOMNameSpaceNode|\DOMNode> */
    private function query(\DOMXPath $xpath, string $expression): \DOMNodeList
    {
        $nodes = $xpath->query($expression);
        if (false === $nodes) {
            self::fail('Invalid XPath expression: '.$expression);
        }

        return $nodes;
    }

    private function tailwindBinary(): ?string
    {
        $configured = getenv('TE4G_TAILWIND_BINARY');

        return is_string($configured) ? $configured : null;
    }

    private function write(string $path, string $contents): void
    {
        $target = $this->projectDirectory.'/'.$path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, $contents);
    }

    private function writeExecutable(string $path, string $versionOutput): string
    {
        $target = $this->projectDirectory.'/'.$path;
        file_put_contents($target, "#!/bin/sh\nprintf '%s\\n' ".escapeshellarg($versionOutput)."\n");
        chmod($target, 0755);

        return $target;
    }

    private function xpathLiteral(string $value): string
    {
        return "'".str_replace("'", "', \"'\", '", $value)."'";
    }
}
