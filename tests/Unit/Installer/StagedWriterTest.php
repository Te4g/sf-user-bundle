<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Exception\InstallerWriteFailed;
use Te4g\UserBundle\Installer\InstallationPlan;
use Te4g\UserBundle\Installer\InstallationValidator;
use Te4g\UserBundle\Installer\InstallationValidatorInterface;
use Te4g\UserBundle\Installer\PlannedFile;
use Te4g\UserBundle\Installer\ProjectLayout;
use Te4g\UserBundle\Installer\StagedWriter;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class StagedWriterTest extends TestCase
{
    private string $projectDir;
    private ProjectLayout $layout;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/te4g-user-writer-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
        $this->layout = ProjectLayout::at($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->remove($this->projectDir);
    }

    public function testApplyWritesValidatedFilesAndPreservesManagedFileMode(): void
    {
        $this->write('src/Existing.php', "<?php\nreturn 1;\n", 0600);
        $plan = InstallationPlan::forLayout($this->layout, [
            PlannedFile::managedUpdate(
                'src/Existing.php',
                hash('sha256', "<?php\nreturn 1;\n"),
                "<?php\nreturn 2;\n",
            ),
            PlannedFile::create('config/new.json', "{\"enabled\":true}\n"),
        ]);

        $result = $this->writer()->apply($plan);

        self::assertSame(['src/Existing.php', 'config/new.json'], $result->writtenPaths());
        self::assertSame("<?php\nreturn 2;\n", file_get_contents($this->projectDir.'/src/Existing.php'));
        self::assertSame(0600, fileperms($this->projectDir.'/src/Existing.php') & 0777);
        self::assertSame(0644, fileperms($this->projectDir.'/config/new.json') & 0777);
        self::assertSame([], glob($this->projectDir.'/var/te4g-user-installer/*') ?: []);
    }

    public function testInvalidStagedYamlWritesNothing(): void
    {
        $plan = InstallationPlan::forLayout($this->layout, [
            PlannedFile::create('src/Entity/User.php', "<?php\nfinal class User {}\n"),
            PlannedFile::create('config/packages/broken.yaml', "broken: [\n"),
        ]);

        try {
            $this->writer()->apply($plan);
            self::fail('Invalid YAML must fail staging.');
        } catch (InstallerWriteFailed $exception) {
            self::assertSame('Installer validation failed.', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
        self::assertFileDoesNotExist($this->projectDir.'/config/packages/broken.yaml');
    }

    public function testInvalidPhpJsonAndTwigAreRejectedWithGenericErrors(): void
    {
        foreach ([
            'src/Broken.php' => '<?php function {',
            'config/broken.json' => '{broken',
            'templates/broken.html.twig' => '{% if %}',
        ] as $relativePath => $contents) {
            $plan = InstallationPlan::forLayout($this->layout, [PlannedFile::create($relativePath, $contents)]);

            try {
                $this->writer()->apply($plan);
                self::fail(sprintf('%s should not validate.', $relativePath));
            } catch (InstallerWriteFailed $exception) {
                self::assertSame('Installer validation failed.', $exception->getMessage());
                self::assertStringNotContainsString($contents, $exception->getMessage());
            }

            self::assertFileDoesNotExist($this->projectDir.'/'.$relativePath);
        }
    }

    public function testApplyRollsBackEarlierWritesWhenPostWriteValidationFails(): void
    {
        $this->write('src/Existing.php', "<?php\nreturn 'original';\n", 0600);
        $validator = new class () implements InstallationValidatorInterface {
            public function validate(string $absolutePath, string $relativePath): void
            {
                if (!str_contains($absolutePath, '/var/te4g-user-installer/')) {
                    throw new InstallerWriteFailed('injected secret failure');
                }
            }
        };
        $writer = new StagedWriter($validator);
        $plan = InstallationPlan::forLayout($this->layout, [
            PlannedFile::managedUpdate(
                'src/Existing.php',
                hash('sha256', "<?php\nreturn 'original';\n"),
                "<?php\nreturn 'changed';\n",
            ),
            PlannedFile::create('config/new.json', "{}\n"),
        ]);

        try {
            $writer->apply($plan);
            self::fail('Post-write validation must fail.');
        } catch (InstallerWriteFailed $exception) {
            self::assertSame('Installer write failed.', $exception->getMessage());
        }

        self::assertSame("<?php\nreturn 'original';\n", file_get_contents($this->projectDir.'/src/Existing.php'));
        self::assertSame(0600, fileperms($this->projectDir.'/src/Existing.php') & 0777);
        self::assertFileDoesNotExist($this->projectDir.'/config/new.json');
    }

    public function testApplyRollsBackEarlierWritesWhenALaterTargetMoveCannotStart(): void
    {
        $projectDir = $this->layout->projectDir();
        $validator = new class ($projectDir) implements InstallationValidatorInterface {
            private int $calls = 0;

            public function __construct(private readonly string $projectDir)
            {
            }

            public function validate(string $absolutePath, string $relativePath): void
            {
                ++$this->calls;
                if (2 === $this->calls) {
                    file_put_contents($this->projectDir.'/config', 'blocks the second target directory');
                }
            }
        };
        $plan = InstallationPlan::forLayout($this->layout, [
            PlannedFile::create('src/First.php', "<?php\nfinal class First {}\n"),
            PlannedFile::create('config/second.json', "{}\n"),
        ]);

        try {
            (new StagedWriter($validator))->apply($plan);
            self::fail('The blocked second target must fail.');
        } catch (InstallerWriteFailed $exception) {
            self::assertSame('Installer write failed.', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->projectDir.'/src/First.php');
        self::assertSame('blocks the second target directory', file_get_contents($this->projectDir.'/config'));
    }

    public function testResultCanRollBackCompletedWritesInReverseOrder(): void
    {
        $this->write('first.txt', 'before-first', 0600);
        $this->write('second.txt', 'before-second', 0640);
        $result = $this->writer()->apply(InstallationPlan::forLayout($this->layout, [
            PlannedFile::managedUpdate('first.txt', hash('sha256', 'before-first'), 'after-first'),
            PlannedFile::managedUpdate('second.txt', hash('sha256', 'before-second'), 'after-second'),
            PlannedFile::create('created.txt', 'created'),
        ]));

        $result->rollback();

        self::assertSame('before-first', file_get_contents($this->projectDir.'/first.txt'));
        self::assertSame(0600, fileperms($this->projectDir.'/first.txt') & 0777);
        self::assertSame('before-second', file_get_contents($this->projectDir.'/second.txt'));
        self::assertSame(0640, fileperms($this->projectDir.'/second.txt') & 0777);
        self::assertFileDoesNotExist($this->projectDir.'/created.txt');
        self::assertTrue($result->wasRolledBack());
    }

    public function testManagedUpdateRejectsAChangedFileBeforeWritingAnything(): void
    {
        $this->write('existing.txt', 'application change');
        $plan = InstallationPlan::forLayout($this->layout, [
            PlannedFile::managedUpdate('existing.txt', hash('sha256', 'generated original'), 'generated next'),
            PlannedFile::create('new.txt', 'new'),
        ]);

        $this->expectException(InstallerConflict::class);

        try {
            $this->writer()->apply($plan);
        } finally {
            self::assertSame('application change', file_get_contents($this->projectDir.'/existing.txt'));
            self::assertFileDoesNotExist($this->projectDir.'/new.txt');
        }
    }

    public function testWriterRechecksSymlinkEscapeBeforeApplying(): void
    {
        $outside = sys_get_temp_dir().'/te4g-user-write-outside-'.bin2hex(random_bytes(8));
        mkdir($outside, 0777, true);
        symlink($outside, $this->projectDir.'/linked');

        try {
            $plan = InstallationPlan::forLayout($this->layout, [PlannedFile::create('linked/secret.txt', 'secret')]);
            $this->writer()->apply($plan);
            self::fail('A target through a symlink must be rejected.');
        } catch (InstallerConflict $exception) {
            self::assertSame('Unsafe installer path.', $exception->getMessage());
            self::assertFileDoesNotExist($outside.'/secret.txt');
        } finally {
            unlink($this->projectDir.'/linked');
            rmdir($outside);
        }
    }

    public function testPlanConflictsPreventAllWrites(): void
    {
        $plan = InstallationPlan::forLayout(
            $this->layout,
            [PlannedFile::create('new.txt', 'new')],
            [InstallerConflict::forPath('existing.txt', 'unmanaged-existing-file')],
        );

        $this->expectException(InstallerConflict::class);

        try {
            $this->writer()->apply($plan);
        } finally {
            self::assertFileDoesNotExist($this->projectDir.'/new.txt');
        }
    }

    public function testEmptyPlanDoesNotCreateAStagingDirectory(): void
    {
        $result = $this->writer()->apply(InstallationPlan::forLayout($this->layout, []));

        self::assertSame([], $result->writtenPaths());
        self::assertFileDoesNotExist($this->projectDir.'/var');
    }

    private function writer(): StagedWriter
    {
        return new StagedWriter(new InstallationValidator(new Environment(new ArrayLoader())));
    }

    private function write(string $relativePath, string $contents, int $mode = 0644): void
    {
        $path = $this->projectDir.'/'.$relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
        chmod($path, $mode);
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
