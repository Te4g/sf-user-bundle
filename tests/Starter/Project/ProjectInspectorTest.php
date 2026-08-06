<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Project;

use PHPUnit\Framework\TestCase;
use Te4g\Starter\Exception\InvalidProject;
use Te4g\Starter\Project\Conflict;
use Te4g\Starter\Project\PreflightReport;
use Te4g\Starter\Project\ProjectInspector;
use Te4g\Starter\Project\ProjectLayout;

final class ProjectInspectorTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/te4g-starter-inspector-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testFreshSkeletonAndWebappAreCompatible(): void
    {
        $inspector = new ProjectInspector('8.4.12', '8.1.2');

        self::assertTrue($inspector->inspect($this->layout('bare-skeleton'))->isCompatible());
        self::assertTrue($inspector->inspect($this->layout('webapp'))->isCompatible());
    }

    public function testExistingUserReportsItsExactPath(): void
    {
        $report = (new ProjectInspector('8.4.12', '8.1.2'))->inspect($this->layout('existing-account'));

        self::assertSame(
            'src/Entity/User.php: an account system already exists',
            $report->conflicts()[0]->message(),
        );
    }

    public function testCustomizedSecurityReportsProviderAndFirewallConflicts(): void
    {
        $report = (new ProjectInspector('8.4.12', '8.1.2'))->inspect($this->layout('customized-security'));

        self::assertSame([
            'config/packages/security.yaml: a non-default security firewall is already configured',
            'config/packages/security.yaml: a non-default security provider is already configured',
        ], array_map(static fn (Conflict $conflict): string => $conflict->message(), $report->conflicts()));
    }

    public function testCustomizedDevFirewallReportsAnExactConflict(): void
    {
        $report = (new ProjectInspector('8.4.12', '8.1.2'))->inspect($this->layout('customized-dev-firewall'));

        self::assertSame([
            'config/packages/security.yaml: a non-default security firewall is already configured',
        ], array_map(static fn (Conflict $conflict): string => $conflict->message(), $report->conflicts()));
    }

    public function testDefaultDevFirewallRemainsCompatible(): void
    {
        $report = (new ProjectInspector('8.4.12', '8.1.2'))->inspect($this->layout('default-security'));

        self::assertTrue($report->isCompatible());
    }

    public function testReportsAllConflictsInPathSortedOrder(): void
    {
        $report = (new ProjectInspector('8.3.99', '8.2.0'))->inspect($this->layout('existing-account'));

        self::assertSame([
            'composer.json: PHP 8.4 or newer is required',
            'composer.json: Symfony 8.1.x is required',
            'src/Entity/User.php: an account system already exists',
        ], array_map(static fn (Conflict $conflict): string => $conflict->message(), $report->conflicts()));
    }

    public function testRequiresTheSymfonyFrameworkBundle(): void
    {
        $layout = $this->temporaryLayout('{"require": {}}');
        $report = (new ProjectInspector('8.4.12', '8.1.2'))->inspect($layout);

        self::assertSame([
            'composer.json: symfony/framework-bundle is required',
        ], array_map(static fn (Conflict $conflict): string => $conflict->message(), $report->conflicts()));
    }

    public function testReportsRepositoryAndAccountControllerPaths(): void
    {
        $layout = $this->temporaryLayout(
            '{"require": {"symfony/framework-bundle": "^8.1"}}',
            ['src/Repository/UserRepository.php', 'src/Controller/LoginController.php'],
        );
        $report = (new ProjectInspector('8.4.12', '8.1.2'))->inspect($layout);

        self::assertSame([
            'src/Controller/LoginController.php: an account system already exists',
            'src/Repository/UserRepository.php: an account system already exists',
        ], array_map(static fn (Conflict $conflict): string => $conflict->message(), $report->conflicts()));
    }

    public function testAPathlessConflictUsesItsReasonAsTheMessage(): void
    {
        self::assertSame('a global preflight failure', (new Conflict(null, 'a global preflight failure'))->message());
    }

    public function testIncompatibleReportThrowsAllExactConflictMessages(): void
    {
        $report = new PreflightReport([
            new Conflict('z-last', 'last'),
            new Conflict('a-first', 'first'),
        ]);

        try {
            $report->throwIfIncompatible();
            self::fail('Expected InvalidProject to be thrown.');
        } catch (InvalidProject $exception) {
            self::assertSame("a-first: first\nz-last: last", $exception->getMessage());
        }
    }

    private function layout(string $name): ProjectLayout
    {
        return ProjectLayout::discover(dirname(__DIR__, 2).'/Fixtures/Starter/'.$name);
    }

    /** @param list<string> $files */
    private function temporaryLayout(string $composerJson, array $files = []): ProjectLayout
    {
        $root = $this->temporaryDirectory.'/project';
        self::assertTrue(mkdir($root.'/bin', 0700, true));
        self::assertNotFalse(file_put_contents($root.'/composer.json', $composerJson));
        self::assertNotFalse(file_put_contents($root.'/bin/console', '<?php'));

        foreach ($files as $file) {
            self::assertTrue(mkdir(dirname($root.'/'.$file), 0700, true) || is_dir(dirname($root.'/'.$file)));
            self::assertNotFalse(file_put_contents($root.'/'.$file, '<?php'));
        }

        return ProjectLayout::discover($root);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);

                continue;
            }

            $this->removeDirectory($path);
        }

        rmdir($directory);
    }
}
