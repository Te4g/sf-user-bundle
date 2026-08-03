<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Installer\MarkerEditor;

final class MarkerEditorTest extends TestCase
{
    public function testMissingRegionIsAppendedAndRepeatUpsertIsIdempotent(): void
    {
        $editor = new MarkerEditor();
        $expected = <<<'CSS'
            body {}

            /* <te4g-user name="shadcn"> */
            @import "shadcn";
            /* </te4g-user> */

            CSS;

        $updated = $editor->upsert("body {}\n", 'shadcn', "@import \"shadcn\";\n");

        self::assertSame($expected, $updated);
        self::assertSame($expected, $editor->upsert($updated, 'shadcn', "@import \"shadcn\";\n"));
    }

    public function testNamedRegionIsUpdatedWithoutChangingApplicationContents(): void
    {
        $contents = <<<'CSS'
            application-before
            /* <te4g-user name="shadcn"> */
            old generated
            /* </te4g-user> */
            application-after

            CSS;

        self::assertSame(<<<'CSS'
            application-before
            /* <te4g-user name="shadcn"> */
            new generated
            /* </te4g-user> */
            application-after

            CSS, (new MarkerEditor())->upsert($contents, 'shadcn', "new generated\n"));
    }

    /** Removing any structural validation below would make an ambiguous edit possible. */
    #[DataProvider('malformedRegions')]
    public function testMalformedDuplicateAndNestedRegionsAreConflicts(string $contents): void
    {
        $this->expectException(InstallerConflict::class);
        $this->expectExceptionMessage('Invalid installer marker region.');

        (new MarkerEditor())->upsert($contents, 'shadcn', 'generated');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedRegions(): iterable
    {
        yield 'missing close' => ['/* <te4g-user name="shadcn"> */'];
        yield 'orphan close' => ['/* </te4g-user> */'];
        yield 'malformed close' => ['/* </te4g-user > */'];
        yield 'duplicate target' => [<<<'CSS'
            /* <te4g-user name="shadcn"> */
            one
            /* </te4g-user> */
            /* <te4g-user name="shadcn"> */
            two
            /* </te4g-user> */
            CSS];
        yield 'nested region' => [<<<'CSS'
            /* <te4g-user name="shadcn"> */
            /* <te4g-user name="nested"> */
            /* </te4g-user> */
            /* </te4g-user> */
            CSS];
    }

    public function testGeneratedPayloadCannotContainMarkers(): void
    {
        $this->expectException(InstallerConflict::class);
        $this->expectExceptionMessage('Invalid installer marker region.');

        (new MarkerEditor())->upsert('', 'shadcn', '/* <te4g-user name="nested"> */');
    }

    public function testGeneratedPayloadCannotContainClosingMarkerPrefixVariant(): void
    {
        $this->expectException(InstallerConflict::class);
        $this->expectExceptionMessage('Invalid installer marker region.');

        (new MarkerEditor())->upsert('', 'shadcn', '/* </te4g-user altered> */');
    }

    public function testUnsafeMarkerNameIsRejected(): void
    {
        $this->expectException(InstallerConflict::class);
        $this->expectExceptionMessage('Invalid installer marker region.');

        (new MarkerEditor())->upsert('', 'bad\" name', 'generated');
    }
}
