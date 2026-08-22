<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Generation;

use PHPUnit\Framework\TestCase;
use Te4g\Starter\Generation\ReplacementPolicy;
use Te4g\Starter\Project\ProjectLayout;

final class ReplacementPolicyTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/te4g-starter-policy-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testAbsentResourceIsPlannedForCreation(): void
    {
        $plan = $this->policy()->plan($this->layout(), [
            'src/Entity/User.php' => $this->generatedUserPath(),
        ]);

        self::assertSame('src/Entity/User.php', $plan->writes()[0]->relativePath());
        self::assertTrue($plan->writes()[0]->isCreation());
    }

    public function testRecognizedFreshSymfonyBaselineIsReplaceable(): void
    {
        $layout = $this->layout();
        $path = $layout->path('config/packages/security.yaml');
        self::assertTrue(mkdir(dirname($path), 0700, true));
        self::assertNotFalse(copy($this->baseline('config/packages/security.yaml'), $path));

        $plan = $this->policy()->plan($layout, [
            'config/packages/security.yaml' => $this->generatedSecurityPath(),
        ]);

        self::assertTrue($plan->writes()[0]->isReplacement());
    }

    public function testRecognizedFreshWebappTemplateBaselineIsReplaceable(): void
    {
        $plan = $this->planForTemplateBaseline('webapp');

        self::assertSame([], $plan->conflicts());
        self::assertTrue($plan->writes()[0]->isReplacement());
    }

    public function testRecognizedFreshTwigOnlyTemplateBaselineIsReplaceable(): void
    {
        $plan = $this->planForTemplateBaseline('twig-only');

        self::assertSame([], $plan->conflicts());
        self::assertTrue($plan->writes()[0]->isReplacement());
    }

    public function testCustomizedTwigOnlyTemplateIsAnExactConflict(): void
    {
        $layout = $this->layout();
        $path = $layout->path('templates/base.html.twig');
        self::assertTrue(mkdir(dirname($path), 0700, true));
        $contents = file_get_contents($this->baseline('templates/base.html.twig', 'twig-only'));
        self::assertIsString($contents);
        self::assertNotFalse(file_put_contents($path, $contents."{# customized #}\n"));

        $plan = $this->policy()->plan($layout, [
            'templates/base.html.twig' => $this->generatedTemplatePath(),
        ]);

        self::assertSame(
            'templates/base.html.twig: existing content is not a supported fresh Symfony baseline',
            $plan->conflicts()[0]->message(),
        );
    }

    public function testRecognizedFreshResetPasswordRecipeBaselineIsReplaceable(): void
    {
        $layout = $this->layout();
        $path = $layout->path('config/packages/reset_password.yaml');
        self::assertTrue(mkdir(dirname($path), 0700, true));
        self::assertNotFalse(file_put_contents($path, <<<'YAML'
symfonycasts_reset_password:
    # Replace symfonycasts.reset_password.fake_request_repository with the full
    # namespace of the password reset request repository after it has been created.
    # i.e. App\Repository\ResetPasswordRequestRepository
    request_password_repository: symfonycasts.reset_password.fake_request_repository
YAML."\n"));

        $plan = $this->policy()->plan($layout, [
            'config/packages/reset_password.yaml' => $this->generatedSecurityPath(),
        ]);

        self::assertSame([], $plan->conflicts());
        self::assertTrue($plan->writes()[0]->isReplacement());
    }

    public function testUnknownExistingFileIsAnExactConflict(): void
    {
        $layout = $this->layout();
        $path = $layout->path('config/packages/security.yaml');
        self::assertTrue(mkdir(dirname($path), 0700, true));
        self::assertNotFalse(file_put_contents($path, 'custom security'));

        $plan = $this->policy()->plan($layout, [
            'config/packages/security.yaml' => $this->generatedSecurityPath(),
        ]);

        self::assertSame(
            'config/packages/security.yaml: existing content is not a supported fresh Symfony baseline',
            $plan->conflicts()[0]->message(),
        );
    }

    public function testExistingUserReportsTheAccountSystemReason(): void
    {
        $layout = $this->layout();
        $path = $layout->path('src/Entity/User.php');
        self::assertTrue(mkdir(dirname($path), 0700, true));
        self::assertNotFalse(file_put_contents($path, '<?php'));

        $plan = $this->policy()->plan($layout, [
            'src/Entity/User.php' => $this->generatedUserPath(),
        ]);

        self::assertSame('src/Entity/User.php: an account system already exists', $plan->conflicts()[0]->message());
    }

    private function policy(): ReplacementPolicy
    {
        return new ReplacementPolicy([
            dirname(__DIR__, 3).'/resources/baselines/webapp',
            dirname(__DIR__, 3).'/resources/baselines/twig-only',
        ]);
    }

    private function generatedSecurityPath(): string
    {
        return $this->temporaryDirectory.'/generated-security.yaml';
    }

    private function generatedUserPath(): string
    {
        return dirname(__DIR__, 2).'/Fixtures/Starter/resource-tree/src/Entity/User.php';
    }

    private function generatedTemplatePath(): string
    {
        return dirname(__DIR__, 3).'/resources/application/templates/base.html.twig';
    }

    private function baseline(string $path, string $variant = 'webapp'): string
    {
        return dirname(__DIR__, 3).'/resources/baselines/'.$variant.'/'.$path;
    }

    private function planForTemplateBaseline(string $variant): \Te4g\Starter\Generation\GenerationPlan
    {
        $layout = $this->layout();
        $path = $layout->path('templates/base.html.twig');
        self::assertTrue(mkdir(dirname($path), 0700, true));
        self::assertNotFalse(copy($this->baseline('templates/base.html.twig', $variant), $path));

        return $this->policy()->plan($layout, [
            'templates/base.html.twig' => $this->generatedTemplatePath(),
        ]);
    }

    private function layout(): ProjectLayout
    {
        $root = $this->temporaryDirectory.'/project';
        self::assertTrue(mkdir($root.'/bin', 0700, true));
        self::assertNotFalse(file_put_contents($root.'/composer.json', '{}'));
        self::assertNotFalse(file_put_contents($root.'/bin/console', '<?php'));

        return ProjectLayout::discover($root);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
