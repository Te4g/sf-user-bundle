<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Generation;

use PHPUnit\Framework\TestCase;
use Te4g\Starter\Generation\ResourceCatalog;

final class ResourceCatalogTest extends TestCase
{
    public function testCatalogMapsApplicationTreeWithoutTemplateSuffixes(): void
    {
        $files = (new ResourceCatalog($this->fixture('resource-tree')))->files();

        self::assertSame(
            $this->fixture('resource-tree/src/Entity/User.php'),
            $files['src/Entity/User.php'],
        );
        self::assertArrayNotHasKey('src/Entity/User.php.tpl', $files);
    }

    public function testCatalogKeepsOrdinaryFilenamesContainingConsecutiveDots(): void
    {
        $files = (new ResourceCatalog($this->fixture('resource-tree')))->files();

        self::assertSame(
            $this->fixture('resource-tree/assets/Version..json'),
            $files['assets/Version..json'],
        );
    }

    private function fixture(string $path): string
    {
        return dirname(__DIR__, 2).'/Fixtures/Starter/'.$path;
    }
}
