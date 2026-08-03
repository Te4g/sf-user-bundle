<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Exception\InstallerRollbackIncomplete;
use Te4g\UserBundle\Exception\InstallerWriteFailed;
use Te4g\UserBundle\Installer\InstallationCreateRaceHookInterface;
use Te4g\UserBundle\Installer\InstallationPlan;
use Te4g\UserBundle\Installer\InstallationPostWriteRaceHookInterface;
use Te4g\UserBundle\Installer\InstallationRaceHookInterface;
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
        self::assertSame(
            [$this->projectDir.'/var/te4g-user-installer/install.lock'],
            glob($this->projectDir.'/var/te4g-user-installer/*') ?: [],
        );
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
                self::assertNull($exception->getPrevious());
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

    public function testApplyRevalidatesEveryWrittenSnapshotAfterAllPostWriteValidation(): void
    {
        $validator = new class ($this->projectDir) implements InstallationValidatorInterface {
            public function __construct(private readonly string $projectDir)
            {
            }

            public function validate(string $absolutePath, string $relativePath): void
            {
                if ('second.txt' === $relativePath
                    && !str_contains($absolutePath, '/var/te4g-user-installer/')
                ) {
                    file_put_contents($this->projectDir.'/first.txt', 'later application edit');
                }
            }
        };

        try {
            (new StagedWriter($validator))->apply(InstallationPlan::forLayout($this->layout, [
                PlannedFile::create('first.txt', 'generated first'),
                PlannedFile::create('second.txt', 'generated second'),
            ]));
            self::fail('A late edit to an earlier write must fail the whole apply operation.');
        } catch (InstallerRollbackIncomplete $exception) {
            self::assertSame('write-failed', $exception->primaryOutcome());
            self::assertSame('ownership-conflict', $exception->rollbackOutcome());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame('later application edit', file_get_contents($this->projectDir.'/first.txt'));
        self::assertFileDoesNotExist($this->projectDir.'/second.txt');
    }

    public function testFinalRevalidationRejectsSameContentReplacementOfAnEarlierWrite(): void
    {
        $validator = new class ($this->projectDir) implements InstallationValidatorInterface {
            public ?int $replacementInode = null;

            public function __construct(private readonly string $projectDir)
            {
            }

            public function validate(string $absolutePath, string $relativePath): void
            {
                if ('second.txt' !== $relativePath
                    || str_contains($absolutePath, '/var/te4g-user-installer/')
                ) {
                    return;
                }

                $replacement = $this->projectDir.'/application-replacement';
                file_put_contents($replacement, 'generated first');
                $stat = lstat($replacement);
                $this->replacementInode = false === $stat ? null : $stat['ino'];
                rename($replacement, $this->projectDir.'/first.txt');
            }
        };

        try {
            (new StagedWriter($validator))->apply(InstallationPlan::forLayout($this->layout, [
                PlannedFile::create('first.txt', 'generated first'),
                PlannedFile::create('second.txt', 'generated second'),
            ]));
            self::fail('A replacement inode must fail the final transaction-wide revalidation.');
        } catch (InstallerRollbackIncomplete $exception) {
            self::assertSame('ownership-conflict', $exception->rollbackOutcome());
        }

        $currentStat = lstat($this->projectDir.'/first.txt');
        self::assertIsArray($currentStat);
        self::assertSame($validator->replacementInode, $currentStat['ino']);
        self::assertFileDoesNotExist($this->projectDir.'/second.txt');
    }

    public function testFinalRevalidationRejectsAncestorReplacementOfAnEarlierWrite(): void
    {
        mkdir($this->projectDir.'/existing-parent');
        $validator = new class ($this->projectDir) implements InstallationValidatorInterface {
            public function __construct(private readonly string $projectDir)
            {
            }

            public function validate(string $absolutePath, string $relativePath): void
            {
                if ('second.txt' !== $relativePath
                    || str_contains($absolutePath, '/var/te4g-user-installer/')
                ) {
                    return;
                }

                rename($this->projectDir.'/existing-parent', $this->projectDir.'/existing-parent-application');
                mkdir($this->projectDir.'/existing-parent');
            }
        };

        try {
            (new StagedWriter($validator))->apply(InstallationPlan::forLayout($this->layout, [
                PlannedFile::create('existing-parent/first.txt', 'generated first'),
                PlannedFile::create('second.txt', 'generated second'),
            ]));
            self::fail('An ancestor replacement must fail the final transaction-wide revalidation.');
        } catch (InstallerRollbackIncomplete $exception) {
            self::assertSame('ownership-conflict', $exception->rollbackOutcome());
        }

        self::assertSame(
            'generated first',
            file_get_contents($this->projectDir.'/existing-parent-application/first.txt'),
        );
        self::assertFileDoesNotExist($this->projectDir.'/existing-parent/first.txt');
        self::assertFileDoesNotExist($this->projectDir.'/second.txt');
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

    public function testCreateRaceNeverClobbersACompetingApplicationFile(): void
    {
        $hook = new class () implements InstallationCreateRaceHookInterface, InstallationRaceHookInterface {
            public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
            {
            }

            public function beforeCreateLink(ProjectLayout $layout, PlannedFile $file): void
            {
                $path = $layout->path($file->relativePath());
                file_put_contents($path, 'competing application file');
            }

            public function beforeRollback(ProjectLayout $layout, string $relativePath): void
            {
            }
        };
        $plan = InstallationPlan::forLayout($this->layout, [
            PlannedFile::create('created.txt', 'generated'),
        ]);

        $this->expectException(InstallerConflict::class);

        try {
            (new StagedWriter($this->validator(), $hook))->apply($plan);
        } finally {
            self::assertSame('competing application file', file_get_contents($this->projectDir.'/created.txt'));
        }
    }

    public function testSameContentReplacementAfterMutationNeverBecomesInstallerOwned(): void
    {
        $hook = new class () implements InstallationPostWriteRaceHookInterface, InstallationRaceHookInterface {
            public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
            {
            }

            public function afterTargetMutation(ProjectLayout $layout, PlannedFile $file): void
            {
                $replacement = $layout->path('application-replacement');
                file_put_contents($replacement, $file->contents());
                rename($replacement, $layout->path($file->relativePath()));
            }

            public function beforeRollback(ProjectLayout $layout, string $relativePath): void
            {
            }
        };

        try {
            (new StagedWriter($this->validator(), $hook))->apply(InstallationPlan::forLayout($this->layout, [
                PlannedFile::create('created.txt', 'generated'),
            ]));
            self::fail('A replacement inode must not become installer-owned even when its content is identical.');
        } catch (InstallerConflict $exception) {
            self::assertSame('write-target-changed', $exception->code());
        }

        self::assertSame('generated', file_get_contents($this->projectDir.'/created.txt'));
    }

    public function testSameContentManagedReplacementAfterRenameIsNeverRolledBack(): void
    {
        $this->write('managed.txt', 'generated original');
        $hook = new class () implements InstallationPostWriteRaceHookInterface, InstallationRaceHookInterface {
            public ?int $replacementInode = null;

            public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
            {
            }

            public function afterTargetMutation(ProjectLayout $layout, PlannedFile $file): void
            {
                $replacement = $layout->path('application-replacement');
                file_put_contents($replacement, $file->contents());
                $stat = lstat($replacement);
                $this->replacementInode = false === $stat ? null : $stat['ino'];
                rename($replacement, $layout->path($file->relativePath()));
            }

            public function beforeRollback(ProjectLayout $layout, string $relativePath): void
            {
            }
        };

        try {
            (new StagedWriter($this->validator(), $hook))->apply(InstallationPlan::forLayout($this->layout, [
                PlannedFile::managedUpdate(
                    'managed.txt',
                    hash('sha256', 'generated original'),
                    'generated update',
                ),
            ]));
            self::fail('A post-rename replacement inode must not become installer-owned.');
        } catch (InstallerConflict $exception) {
            self::assertSame('write-target-changed', $exception->code());
        }

        $currentStat = lstat($this->projectDir.'/managed.txt');
        self::assertIsArray($currentStat);
        self::assertSame($hook->replacementInode, $currentStat['ino']);
        self::assertSame('generated update', file_get_contents($this->projectDir.'/managed.txt'));
    }

    public function testManagedTargetRaceIsDetectedImmediatelyBeforeWrite(): void
    {
        $this->write('managed.txt', 'generated original');
        $hook = new class () implements InstallationRaceHookInterface {
            public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
            {
                file_put_contents($layout->path($file->relativePath()), 'concurrent application edit');
            }

            public function beforeRollback(ProjectLayout $layout, string $relativePath): void
            {
            }
        };
        $plan = InstallationPlan::forLayout($this->layout, [
            PlannedFile::managedUpdate('managed.txt', hash('sha256', 'generated original'), 'generated update'),
        ]);

        $this->expectException(InstallerConflict::class);

        try {
            (new StagedWriter($this->validator(), $hook))->apply($plan);
        } finally {
            self::assertSame('concurrent application edit', file_get_contents($this->projectDir.'/managed.txt'));
        }
    }

    public function testAncestorSwapIsDetectedImmediatelyBeforeWrite(): void
    {
        mkdir($this->projectDir.'/existing-parent');
        $hook = new class () implements InstallationRaceHookInterface {
            public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
            {
                $parent = dirname($layout->path($file->relativePath()));
                rename($parent, $parent.'-application');
                mkdir($parent);
            }

            public function beforeRollback(ProjectLayout $layout, string $relativePath): void
            {
            }
        };

        $this->expectException(InstallerConflict::class);

        try {
            (new StagedWriter($this->validator(), $hook))->apply(InstallationPlan::forLayout($this->layout, [
                PlannedFile::create('existing-parent/generated.txt', 'generated'),
            ]));
        } finally {
            self::assertFileDoesNotExist($this->projectDir.'/existing-parent/generated.txt');
            self::assertFileDoesNotExist($this->projectDir.'/existing-parent-application/generated.txt');
        }
    }

    public function testProjectLockRejectsASecondCooperatingWriterUntilCommit(): void
    {
        $firstResult = $this->writer()->apply(InstallationPlan::forLayout($this->layout, [
            PlannedFile::create('first.txt', 'first'),
        ]));

        try {
            $this->writer()->apply(InstallationPlan::forLayout($this->layout, [
                PlannedFile::create('second.txt', 'second'),
            ]));
            self::fail('The second writer must not enter while the first result owns the lock.');
        } catch (InstallerConflict $exception) {
            self::assertSame('installer-locked', $exception->code());
            self::assertFileDoesNotExist($this->projectDir.'/second.txt');
        }

        $firstResult->commit();
        $secondResult = $this->writer()->apply(InstallationPlan::forLayout($this->layout, [
            PlannedFile::create('second.txt', 'second'),
        ]));
        $secondResult->commit();

        self::assertSame('second', file_get_contents($this->projectDir.'/second.txt'));
    }

    public function testRollbackNeverDeletesAUserEditedCreatedFile(): void
    {
        $result = $this->writer()->apply(InstallationPlan::forLayout($this->layout, [
            PlannedFile::create('created.txt', 'generated'),
        ]));
        file_put_contents($this->projectDir.'/created.txt', 'later application edit');

        try {
            $result->rollback();
            self::fail('Rollback must report the ownership conflict.');
        } catch (InstallerRollbackIncomplete $exception) {
            self::assertSame('Installer rollback incomplete.', $exception->getMessage());
            self::assertSame('requested', $exception->primaryOutcome());
            self::assertSame('ownership-conflict', $exception->rollbackOutcome());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame('later application edit', file_get_contents($this->projectDir.'/created.txt'));
    }

    public function testRollbackNeverOverwritesAUserEditedManagedFile(): void
    {
        $this->write('managed.txt', 'generated original');
        $result = $this->writer()->apply(InstallationPlan::forLayout($this->layout, [
            PlannedFile::managedUpdate('managed.txt', hash('sha256', 'generated original'), 'generated update'),
        ]));
        file_put_contents($this->projectDir.'/managed.txt', 'later application edit');

        $this->expectException(InstallerRollbackIncomplete::class);

        try {
            $result->rollback();
        } finally {
            self::assertSame('later application edit', file_get_contents($this->projectDir.'/managed.txt'));
        }
    }

    public function testRollbackRejectsSameContentTargetReplacementByIdentity(): void
    {
        $result = $this->writer()->apply(InstallationPlan::forLayout($this->layout, [
            PlannedFile::create('created.txt', 'generated'),
        ]));
        $replacement = $this->projectDir.'/replacement';
        file_put_contents($replacement, 'generated');
        rename($replacement, $this->projectDir.'/created.txt');

        $this->expectException(InstallerRollbackIncomplete::class);

        try {
            $result->rollback();
        } finally {
            self::assertSame('generated', file_get_contents($this->projectDir.'/created.txt'));
        }
    }

    public function testRollbackRejectsAncestorReplacementBeforeRemovingGeneratedFile(): void
    {
        mkdir($this->projectDir.'/existing-parent');
        $hook = new class () implements InstallationRaceHookInterface {
            public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
            {
            }

            public function beforeRollback(ProjectLayout $layout, string $relativePath): void
            {
                $parent = dirname($layout->path($relativePath));
                rename($parent, $parent.'-application');
                mkdir($parent);
            }
        };
        $result = (new StagedWriter($this->validator(), $hook))->apply(InstallationPlan::forLayout($this->layout, [
            PlannedFile::create('existing-parent/generated.txt', 'generated'),
        ]));

        $this->expectException(InstallerRollbackIncomplete::class);

        try {
            $result->rollback();
        } finally {
            self::assertFileDoesNotExist($this->projectDir.'/existing-parent/generated.txt');
            self::assertSame('generated', file_get_contents($this->projectDir.'/existing-parent-application/generated.txt'));
        }
    }

    public function testInternalApplySurfacesRollbackFailureWithoutLeakingPrimaryOrRollbackSecrets(): void
    {
        $validator = new class () implements InstallationValidatorInterface {
            public function validate(string $absolutePath, string $relativePath): void
            {
                if (!str_contains($absolutePath, '/var/te4g-user-installer/')) {
                    throw new \RuntimeException('PRIMARY-SENTINEL-SECRET');
                }
            }
        };
        $hook = new class () implements InstallationRaceHookInterface {
            public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
            {
            }

            public function beforeRollback(ProjectLayout $layout, string $relativePath): void
            {
                throw new \RuntimeException('ROLLBACK-SENTINEL-SECRET');
            }
        };

        try {
            (new StagedWriter($validator, $hook))->apply(InstallationPlan::forLayout($this->layout, [
                PlannedFile::create('created.txt', 'generated'),
            ]));
            self::fail('Both the primary and rollback operations must fail.');
        } catch (InstallerRollbackIncomplete $exception) {
            self::assertSame('write-failed', $exception->primaryOutcome());
            self::assertSame('operation-failed', $exception->rollbackOutcome());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('PRIMARY-SENTINEL-SECRET', (string) $exception);
            self::assertStringNotContainsString('ROLLBACK-SENTINEL-SECRET', (string) $exception);
        }

        self::assertSame('generated', file_get_contents($this->projectDir.'/created.txt'));
    }

    public function testDirectRollbackContinuesAfterRestoreFailureAndSurfacesIncompleteState(): void
    {
        $this->write('managed.txt', 'original');
        $hook = new class () implements InstallationRaceHookInterface {
            public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
            {
            }

            public function beforeRollback(ProjectLayout $layout, string $relativePath): void
            {
                if ('managed.txt' === $relativePath) {
                    throw new \RuntimeException('RESTORE-SENTINEL-SECRET');
                }
            }
        };
        $result = (new StagedWriter($this->validator(), $hook))->apply(InstallationPlan::forLayout($this->layout, [
            PlannedFile::managedUpdate('managed.txt', hash('sha256', 'original'), 'updated'),
            PlannedFile::create('created.txt', 'created'),
        ]));

        try {
            $result->rollback();
            self::fail('The failed restore must surface incomplete rollback.');
        } catch (InstallerRollbackIncomplete $exception) {
            self::assertSame('operation-failed', $exception->rollbackOutcome());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('RESTORE-SENTINEL-SECRET', (string) $exception);
        }

        self::assertSame('updated', file_get_contents($this->projectDir.'/managed.txt'));
        self::assertFileDoesNotExist($this->projectDir.'/created.txt');
    }

    public function testDirectRollbackContinuesAfterRemoveFailureAndSurfacesIncompleteState(): void
    {
        $this->write('managed.txt', 'original');
        $hook = new class () implements InstallationRaceHookInterface {
            public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
            {
            }

            public function beforeRollback(ProjectLayout $layout, string $relativePath): void
            {
                if ('created.txt' === $relativePath) {
                    throw new \RuntimeException('REMOVE-SENTINEL-SECRET');
                }
            }
        };
        $result = (new StagedWriter($this->validator(), $hook))->apply(InstallationPlan::forLayout($this->layout, [
            PlannedFile::managedUpdate('managed.txt', hash('sha256', 'original'), 'updated'),
            PlannedFile::create('created.txt', 'created'),
        ]));

        $this->expectException(InstallerRollbackIncomplete::class);

        try {
            $result->rollback();
        } finally {
            self::assertSame('original', file_get_contents($this->projectDir.'/managed.txt'));
            self::assertSame('created', file_get_contents($this->projectDir.'/created.txt'));
        }
    }

    public function testValidatorExceptionsContainNoPreviousParserOrPathSecrets(): void
    {
        $validator = $this->validator();
        $sentinelPath = $this->projectDir.'/ABSOLUTE-PATH-SENTINEL.yaml';
        file_put_contents($sentinelPath, "secret: [YAML-SOURCE-SENTINEL\n");

        try {
            $validator->validate($sentinelPath, 'config/safe.yaml');
            self::fail('The malformed YAML must fail validation.');
        } catch (InstallerWriteFailed $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('ABSOLUTE-PATH-SENTINEL', (string) $exception);
            self::assertStringNotContainsString('YAML-SOURCE-SENTINEL', (string) $exception);
        }

        $missingPath = $this->projectDir.'/MISSING-PATH-SENTINEL.json';
        try {
            $validator->validate($missingPath, 'config/safe.json');
            self::fail('The missing file must fail validation.');
        } catch (InstallerWriteFailed $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('MISSING-PATH-SENTINEL', (string) $exception);
        }

        $twigPath = $this->projectDir.'/TWIG-PATH-SENTINEL.html.twig';
        file_put_contents($twigPath, '{% TWIG-SOURCE-SENTINEL %}');
        try {
            $validator->validate($twigPath, 'templates/safe.html.twig');
            self::fail('The invalid Twig source must fail validation.');
        } catch (InstallerWriteFailed $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('TWIG-PATH-SENTINEL', (string) $exception);
            self::assertStringNotContainsString('TWIG-SOURCE-SENTINEL', (string) $exception);
        }
    }

    private function writer(): StagedWriter
    {
        return new StagedWriter($this->validator());
    }

    private function validator(): InstallationValidator
    {
        return new InstallationValidator(new Environment(new ArrayLoader()));
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
