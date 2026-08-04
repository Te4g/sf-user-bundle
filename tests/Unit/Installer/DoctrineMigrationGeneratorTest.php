<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Te4g\UserBundle\Exception\MigrationGenerationFailed;
use Te4g\UserBundle\Installer\DoctrineMigrationGenerator;
use Te4g\UserBundle\Installer\InstallerProcessResult;
use Te4g\UserBundle\Installer\InstallerProcessRunnerInterface;

final class DoctrineMigrationGeneratorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/te4g-user-migration-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/bin', 0777, true);
        mkdir($this->projectDir.'/migrations', 0777, true);
        file_put_contents($this->projectDir.'/bin/console', "<?php\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testItRunsTheExactSafeDiffCommandAndReturnsOneNewMigration(): void
    {
        $runner = new RecordingInstallerProcessRunner(function (): int {
            file_put_contents($this->projectDir.'/migrations/Version20260803010101.php', '<?php');

            return 0;
        });

        $path = (new DoctrineMigrationGenerator($runner))->generate($this->projectDir);

        $canonicalProjectDir = realpath($this->projectDir);
        self::assertIsString($canonicalProjectDir);
        self::assertSame('migrations/Version20260803010101.php', $path);
        self::assertSame([[
            'command' => [
                PHP_BINARY,
                $canonicalProjectDir.'/bin/console',
                'doctrine:migrations:diff',
                '--no-interaction',
            ],
            'cwd' => $canonicalProjectDir,
            'timeout' => 120.0,
        ]], $runner->calls);
        self::assertNotContains('doctrine:migrations:migrate', $runner->calls[0]['command']);
    }

    public function testNoMappingChangeReturnsNull(): void
    {
        $runner = new RecordingInstallerProcessRunner(static fn (): int => 0);

        self::assertNull((new DoctrineMigrationGenerator($runner))->generate($this->projectDir));
    }

    public function testNonZeroExitBecomesGenericUnchainedFailure(): void
    {
        $runner = new RecordingInstallerProcessRunner(static fn (): int => 17);

        try {
            (new DoctrineMigrationGenerator($runner))->generate($this->projectDir);
            self::fail('A non-zero migration diff must fail.');
        } catch (MigrationGenerationFailed $exception) {
            self::assertSame('Migration generation failed.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testMultipleNewFilesAreRejectedWithoutLeakingTheirNames(): void
    {
        $runner = new RecordingInstallerProcessRunner(function (): int {
            file_put_contents($this->projectDir.'/migrations/SecretCustomerVersion.php', '<?php');
            file_put_contents($this->projectDir.'/migrations/SecondVersion.php', '<?php');

            return 0;
        });

        try {
            (new DoctrineMigrationGenerator($runner))->generate($this->projectDir);
            self::fail('Multiple migrations from one diff must fail.');
        } catch (MigrationGenerationFailed $exception) {
            self::assertSame('Migration generation failed.', $exception->getMessage());
            self::assertStringNotContainsString('SecretCustomer', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testNewSymlinkIsRejected(): void
    {
        $outside = $this->projectDir.'/outside.php';
        file_put_contents($outside, '<?php');
        $runner = new RecordingInstallerProcessRunner(function () use ($outside): int {
            symlink($outside, $this->projectDir.'/migrations/VersionLink.php');

            return 0;
        });

        $this->expectException(MigrationGenerationFailed::class);
        $this->expectExceptionMessage('Migration generation failed.');

        (new DoctrineMigrationGenerator($runner))->generate($this->projectDir);
    }

    public function testExistingMigrationMutationIsRejected(): void
    {
        file_put_contents($this->projectDir.'/migrations/VersionExisting.php', 'original');
        $runner = new RecordingInstallerProcessRunner(function (): int {
            file_put_contents($this->projectDir.'/migrations/VersionExisting.php', 'mutated');

            return 0;
        });

        $this->expectException(MigrationGenerationFailed::class);
        $this->expectExceptionMessage('Migration generation failed.');

        (new DoctrineMigrationGenerator($runner))->generate($this->projectDir);
    }

    public function testMigrationDirectorySymlinkEscapeFailsBeforeRunningAProcess(): void
    {
        rmdir($this->projectDir.'/migrations');
        $outside = sys_get_temp_dir().'/te4g-user-migration-outside-'.bin2hex(random_bytes(8));
        mkdir($outside);
        symlink($outside, $this->projectDir.'/migrations');
        $runner = new RecordingInstallerProcessRunner(static fn (): int => 0);

        try {
            (new DoctrineMigrationGenerator($runner))->generate($this->projectDir);
            self::fail('A symlinked migration directory must fail.');
        } catch (MigrationGenerationFailed $exception) {
            self::assertSame('Migration generation failed.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        } finally {
            unlink($this->projectDir.'/migrations');
            rmdir($outside);
        }

        self::assertSame([], $runner->calls);
    }
}

final class RecordingInstallerProcessRunner implements InstallerProcessRunnerInterface
{
    /** @var list<array{command: list<string>, cwd: string, timeout: float}> */
    public array $calls = [];

    /** @param \Closure(): int $callback */
    public function __construct(private readonly \Closure $callback)
    {
    }

    public function run(array $command, string $cwd, float $timeout): InstallerProcessResult
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'timeout' => $timeout];

        return new InstallerProcessResult(($this->callback)());
    }
}
