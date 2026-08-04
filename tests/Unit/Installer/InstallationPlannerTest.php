<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Installer\InstallationManifest;
use Te4g\UserBundle\Installer\InstallationPlanner;
use Te4g\UserBundle\Installer\ManifestStore;
use Te4g\UserBundle\Installer\PlannedFile;
use Te4g\UserBundle\Installer\ProjectLayout;

final class InstallationPlannerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/te4g-user-planner-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->projectDir);
    }

    /** A missing rejection for any of these values would permit a target outside the project. */
    #[DataProvider('unsafePaths')]
    public function testProjectLayoutRejectsUnsafeRelativePaths(string $path): void
    {
        $this->expectException(InstallerConflict::class);
        $this->expectExceptionMessage('Unsafe installer path.');

        ProjectLayout::at($this->projectDir)->path($path);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'unix absolute' => ['/tmp/stolen'];
        yield 'windows absolute' => ['C:\\temp\\stolen'];
        yield 'parent traversal' => ['../../../.env'];
        yield 'embedded parent traversal' => ['src/../.env'];
        yield 'windows parent traversal' => ['src\\..\\.env'];
        yield 'nul byte' => ["src/Entity/User.php\0.yaml"];
    }

    public function testProjectLayoutRejectsSymlinkEscape(): void
    {
        $outside = sys_get_temp_dir().'/te4g-user-outside-'.bin2hex(random_bytes(8));
        mkdir($outside, 0777, true);
        symlink($outside, $this->projectDir.'/escaped');

        try {
            ProjectLayout::at($this->projectDir)->path('escaped/secret.php');
            self::fail('A symlink outside the project must be rejected.');
        } catch (InstallerConflict $exception) {
            self::assertSame('Unsafe installer path.', $exception->getMessage());
        } finally {
            unlink($this->projectDir.'/escaped');
            rmdir($outside);
        }
    }

    public function testFreshFilesArePlannedInStablePathOrderWithoutWriting(): void
    {
        $layout = ProjectLayout::at($this->projectDir);
        $planner = $this->planner();

        $plan = $planner->plan($layout, [
            'templates/z.html.twig' => 'z',
            'src/Entity/User.php' => '<?php',
        ]);

        self::assertSame(
            ['src/Entity/User.php', 'templates/z.html.twig'],
            array_map(static fn (PlannedFile $file): string => $file->relativePath(), $plan->files()),
        );
        self::assertSame(['create', 'create'], array_map(static fn (PlannedFile $file): string => $file->mode(), $plan->files()));
        self::assertFalse($plan->hasConflicts());
        self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
    }

    public function testUnmodifiedManagedFileIsPlannedAsManagedUpdate(): void
    {
        $this->write('templates/components/Button.html.twig', 'generated original');
        $this->writeManifest([
            'templates/components/Button.html.twig' => hash('sha256', 'generated original'),
        ]);

        $plan = $this->planner()->plan(ProjectLayout::at($this->projectDir), [
            'templates/components/Button.html.twig' => 'generated update',
        ]);

        self::assertFalse($plan->hasConflicts());
        self::assertCount(1, $plan->files());
        self::assertSame('managed-update', $plan->files()[0]->mode());
        self::assertSame(hash('sha256', 'generated original'), $plan->files()[0]->priorHash());
    }

    public function testModifiedGeneratedFileIsReportedAndNotOverwritten(): void
    {
        $this->write('templates/components/Button.html.twig', 'application edit');
        $this->writeManifest([
            'templates/components/Button.html.twig' => hash('sha256', 'generated original'),
        ]);

        $plan = $this->planner()->plan(ProjectLayout::at($this->projectDir), [
            'templates/components/Button.html.twig' => 'generated update',
        ]);

        self::assertTrue($plan->hasConflicts());
        self::assertSame([], $plan->files());
        self::assertSame('modified-managed-file', $plan->conflicts()[0]->code());
        self::assertSame('templates/components/Button.html.twig', $plan->conflicts()[0]->relativePath());
        self::assertSame('application edit', file_get_contents($this->projectDir.'/templates/components/Button.html.twig'));
    }

    public function testUnmanagedExistingFileIsAConflict(): void
    {
        $this->write('src/Entity/User.php', 'application user');

        $plan = $this->planner()->plan(ProjectLayout::at($this->projectDir), [
            'src/Entity/User.php' => 'generated user',
        ]);

        self::assertTrue($plan->hasConflicts());
        self::assertSame('unmanaged-existing-file', $plan->conflicts()[0]->code());
        self::assertSame('application user', file_get_contents($this->projectDir.'/src/Entity/User.php'));
    }

    public function testExactFlexBootstrapIsPlannedAsAControlledFirstInstallReplacement(): void
    {
        $bootstrap = "te4g_user:\n    enabled: false\n";
        $generated = "te4g_user:\n    enabled: true\n";
        $this->write('config/packages/te4g_user.yaml', $bootstrap);

        $plan = $this->planner()->plan(ProjectLayout::at($this->projectDir), [
            'config/packages/te4g_user.yaml' => $generated,
        ]);

        self::assertFalse($plan->hasConflicts());
        self::assertCount(1, $plan->files());
        self::assertSame('managed-update', $plan->files()[0]->mode());
        self::assertSame(hash('sha256', $bootstrap), $plan->files()[0]->priorHash());
        self::assertSame($bootstrap, file_get_contents($this->projectDir.'/config/packages/te4g_user.yaml'));
    }

    /** The Flex handoff is a byte-exact predecessor, never a generic unmanaged-file overwrite rule. */
    #[DataProvider('flexBootstrapLookalikes')]
    public function testFlexBootstrapLookalikesRemainUnmanagedConflicts(string $path, string $contents): void
    {
        $this->write($path, $contents);

        $plan = $this->planner()->plan(ProjectLayout::at($this->projectDir), [
            $path => "te4g_user:\n    enabled: true\n",
        ]);

        self::assertTrue($plan->hasConflicts());
        self::assertSame([], $plan->files());
        self::assertSame('unmanaged-existing-file', $plan->conflicts()[0]->code());
        self::assertSame($contents, file_get_contents($this->projectDir.'/'.$path));
    }

    /** @return iterable<string, array{string, string}> */
    public static function flexBootstrapLookalikes(): iterable
    {
        yield 'extra comment' => [
            'config/packages/te4g_user.yaml',
            "# application-owned\nte4g_user:\n    enabled: false\n",
        ];
        yield 'extra option' => [
            'config/packages/te4g_user.yaml',
            "te4g_user:\n    enabled: false\n    features: { account_deletion: false }\n",
        ];
        yield 'missing final newline' => [
            'config/packages/te4g_user.yaml',
            "te4g_user:\n    enabled: false",
        ];
        yield 'different target' => [
            'config/packages/te4g_user_bootstrap.yaml',
            "te4g_user:\n    enabled: false\n",
        ];
    }

    public function testExactFlexBootstrapWithExistingManifestOwnershipMismatchRemainsAConflict(): void
    {
        $bootstrap = "te4g_user:\n    enabled: false\n";
        $this->write('config/packages/te4g_user.yaml', $bootstrap);
        $this->writeManifest([
            'config/packages/te4g_user.yaml' => hash('sha256', "te4g_user:\n    enabled: true\n"),
        ]);

        $plan = $this->planner()->plan(ProjectLayout::at($this->projectDir), [
            'config/packages/te4g_user.yaml' => "te4g_user:\n    enabled: true\n",
        ]);

        self::assertTrue($plan->hasConflicts());
        self::assertSame([], $plan->files());
        self::assertSame('modified-managed-file', $plan->conflicts()[0]->code());
        self::assertSame($bootstrap, file_get_contents($this->projectDir.'/config/packages/te4g_user.yaml'));
    }

    public function testMatchingManagedFileMakesRepeatPlanANoOpAndKeepsManifestTimestamp(): void
    {
        $this->write('src/Entity/User.php', 'generated user');
        $this->writeManifest(
            ['src/Entity/User.php' => hash('sha256', 'generated user')],
            '2026-08-03T12:00:00+00:00',
        );

        $plan = $this->planner()->plan(ProjectLayout::at($this->projectDir), [
            'src/Entity/User.php' => 'generated user',
        ]);

        self::assertFalse($plan->hasConflicts());
        self::assertSame([], $plan->files());
        self::assertSame('2026-08-03T12:00:00+00:00', $plan->manifest()->generatedAt()->format(DATE_ATOM));
    }

    public function testMissingManagedFileIsPlannedForRecreation(): void
    {
        $this->writeManifest(['src/Entity/User.php' => hash('sha256', 'old generated user')]);

        $plan = $this->planner()->plan(ProjectLayout::at($this->projectDir), [
            'src/Entity/User.php' => 'new generated user',
        ]);

        self::assertCount(1, $plan->files());
        self::assertSame('create', $plan->files()[0]->mode());
        self::assertSame(hash('sha256', 'new generated user'), $plan->manifest()->files()['src/Entity/User.php']);
    }

    public function testManifestEncodingHasStableShapeAndSortedFiles(): void
    {
        $manifest = InstallationManifest::create(
            '0.1.0',
            new \DateTimeImmutable('2026-08-03T12:00:00+00:00'),
            [
                'z.json' => str_repeat('b', 64),
                'a.php' => str_repeat('a', 64),
            ],
        );

        self::assertSame(<<<'JSON'
            {
                "bundleVersion": "0.1.0",
                "generatedAt": "2026-08-03T12:00:00+00:00",
                "files": {
                    "a.php": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
                    "z.json": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
                }
            }

            JSON, $manifest->toJson());
    }

    public function testMalformedManifestBecomesGenericConflict(): void
    {
        $this->write(ManifestStore::RELATIVE_PATH, '{not json');

        $this->expectException(InstallerConflict::class);
        $this->expectExceptionMessage('Invalid installer manifest.');

        $this->planner()->plan(ProjectLayout::at($this->projectDir), []);
    }

    #[DataProvider('unsafeManifestJson')]
    public function testManifestRejectsUnsafePathsAndInvalidHashTypesWithGenericConflict(string $json): void
    {
        $this->expectException(InstallerConflict::class);
        $this->expectExceptionMessage('Invalid installer manifest.');

        InstallationManifest::fromJson($json);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeManifestJson(): iterable
    {
        yield 'parent path' => ['{"bundleVersion":"0.1.0","generatedAt":"2026-08-03T12:00:00+00:00","files":{"../.env":"'.str_repeat('a', 64).'"}}'];
        yield 'absolute path' => ['{"bundleVersion":"0.1.0","generatedAt":"2026-08-03T12:00:00+00:00","files":{"/tmp/file":"'.str_repeat('a', 64).'"}}'];
        yield 'non-string hash' => ['{"bundleVersion":"0.1.0","generatedAt":"2026-08-03T12:00:00+00:00","files":{"a.php":42}}'];
        yield 'non canonical timestamp' => ['{"bundleVersion":"0.1.0","generatedAt":"tomorrow","files":{}}'];
    }

    public function testPlanRejectsNonCanonicalPathsBeforeTheyCanAliasTheSameTarget(): void
    {
        $this->expectException(InstallerConflict::class);
        $this->expectExceptionMessage('Unsafe installer path.');

        \Te4g\UserBundle\Installer\InstallationPlan::forLayout(ProjectLayout::at($this->projectDir), [
            PlannedFile::create('src//User.php', '<?php'),
            PlannedFile::create('src/User.php', '<?php'),
        ]);
    }

    private function planner(): InstallationPlanner
    {
        return new InstallationPlanner(
            new ManifestStore('0.1.0'),
            new \DateTimeImmutable('2026-08-04T10:00:00+00:00'),
        );
    }

    /** @param array<string, string> $files */
    private function writeManifest(array $files, string $generatedAt = '2026-08-03T12:00:00+00:00'): void
    {
        $manifest = InstallationManifest::create('0.1.0', new \DateTimeImmutable($generatedAt), $files);
        $this->write(ManifestStore::RELATIVE_PATH, $manifest->toJson());
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->projectDir.'/'.$relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (!is_dir($path) || is_link($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->remove($path.'/'.$entry);
        }

        rmdir($path);
    }
}
