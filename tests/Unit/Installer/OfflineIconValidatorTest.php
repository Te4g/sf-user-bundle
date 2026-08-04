<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Te4g\UserBundle\Exception\InstallerExecutionFailed;
use Te4g\UserBundle\Installer\OfflineIconValidator;
use Te4g\UserBundle\Installer\ProjectLayout;

final class OfflineIconValidatorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/te4g-user-offline-icons-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/assets/icons/lucide', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testManagedTemplateIconsMustBePinnedLocallyWithoutChangingFiles(): void
    {
        $managedFiles = $this->managedFiles();
        file_put_contents($this->projectDir.'/assets/icons/lucide/cloud.svg', $managedFiles['assets/icons/lucide/cloud.svg']);
        $before = $this->inventory();

        (new OfflineIconValidator())->validate(ProjectLayout::at($this->projectDir), $managedFiles);

        self::assertSame($before, $this->inventory());
    }

    public function testMissingPinnedIconFailsGenerically(): void
    {
        $this->expectException(InstallerExecutionFailed::class);
        $this->expectExceptionMessage('Installation failed.');

        (new OfflineIconValidator())->validate(ProjectLayout::at($this->projectDir), $this->managedFiles());
    }

    public function testSymlinkedPinnedIconFailsClosed(): void
    {
        $outside = $this->projectDir.'-outside.svg';
        file_put_contents($outside, '<svg/>');
        symlink($outside, $this->projectDir.'/assets/icons/lucide/cloud.svg');

        try {
            (new OfflineIconValidator())->validate(ProjectLayout::at($this->projectDir), $this->managedFiles());
            self::fail('A symlinked icon must fail validation.');
        } catch (InstallerExecutionFailed $exception) {
            self::assertSame('Installation failed.', $exception->getMessage());
        } finally {
            unlink($outside);
        }
    }

    public function testManagedTemplateCannotReferenceAnUnpinnedIcon(): void
    {
        $managedFiles = [
            'templates/page.html.twig' => "{{ ux_icon('lucide:cloud') }}\n",
        ];
        file_put_contents($this->projectDir.'/assets/icons/lucide/cloud.svg', '<svg/>');

        $this->expectException(InstallerExecutionFailed::class);

        (new OfflineIconValidator())->validate(ProjectLayout::at($this->projectDir), $managedFiles);
    }

    /** @return array<string, string> */
    private function managedFiles(): array
    {
        return [
            'assets/icons/lucide/cloud.svg' => '<svg/>',
            'templates/page.html.twig' => "{{ ux_icon('lucide:cloud') }}\n",
        ];
    }

    /** @return array<string, string> */
    private function inventory(): array
    {
        $inventory = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $path => $info) {
            if ($info->isFile()) {
                $hash = hash_file('sha256', (string) $path);
                self::assertIsString($hash);
                $inventory[(string) $path] = $hash;
            }
        }
        ksort($inventory, SORT_STRING);

        return $inventory;
    }
}
