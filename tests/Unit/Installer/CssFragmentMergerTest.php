<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Installer\CssFragmentMerger;

final class CssFragmentMergerTest extends TestCase
{
    private const string FRAGMENT = <<<'CSS'
        @import "tailwindcss";
        @import "../vendor/shadcn/dist/tailwind.css";
        @import "../vendor/tw-animate-css/dist/tw-animate.css";

        /* <te4g-user name="shadcn"> */
        :root { --background: white; }
        /* </te4g-user> */

        CSS;

    public function testMissingImportsEnterThePreambleAndNamedRulesAreIdempotentlyUpserted(): void
    {
        $application = <<<'CSS'
            @import "tailwindcss";

            .application { color: red; }

            CSS;
        $expected = <<<'CSS'
            @import "tailwindcss";
            @import "../vendor/shadcn/dist/tailwind.css";
            @import "../vendor/tw-animate-css/dist/tw-animate.css";

            .application { color: red; }

            /* <te4g-user name="shadcn"> */
            :root { --background: white; }
            /* </te4g-user> */

            CSS;

        $merger = new CssFragmentMerger();
        $merged = $merger->merge($application, self::FRAGMENT);

        self::assertSame($expected, $merged);
        self::assertSame($expected, $merger->merge($merged, self::FRAGMENT));
    }

    public function testExistingNamedRulesAreReplacedWithoutChangingApplicationRules(): void
    {
        $application = <<<'CSS'
            @import "tailwindcss";
            @import "../vendor/shadcn/dist/tailwind.css";
            @import "../vendor/tw-animate-css/dist/tw-animate.css";

            .before { color: red; }
            /* <te4g-user name="shadcn"> */
            old rules
            /* </te4g-user> */
            .after { color: blue; }

            CSS;

        $merged = (new CssFragmentMerger())->merge($application, self::FRAGMENT);

        self::assertStringContainsString('.before { color: red; }', $merged);
        self::assertStringContainsString('.after { color: blue; }', $merged);
        self::assertStringNotContainsString('old rules', $merged);
        self::assertSame(1, substr_count($merged, ':root { --background: white; }'));
    }

    /** A malformed import or marker would make the Task 14 merge ambiguous. */
    #[DataProvider('conflictingStylesheets')]
    public function testMalformedDuplicateMarkersAndImportConflictsAreRejected(string $application): void
    {
        $this->expectException(InstallerConflict::class);

        (new CssFragmentMerger())->merge($application, self::FRAGMENT);
    }

    /** @return iterable<string, array{string}> */
    public static function conflictingStylesheets(): iterable
    {
        yield 'required import after a rule' => [<<<'CSS'
            .application { color: red; }
            @import "tailwindcss";
            CSS];
        yield 'same import has incompatible qualifiers' => [<<<'CSS'
            @import "tailwindcss" layer(base);
            .application { color: red; }
            CSS];
        yield 'duplicate existing marker' => [<<<'CSS'
            /* <te4g-user name="shadcn"> */
            one
            /* </te4g-user> */
            /* <te4g-user name="shadcn"> */
            two
            /* </te4g-user> */
            CSS];
        yield 'duplicate required import' => [<<<'CSS'
            @import "tailwindcss";
            @import "tailwindcss";
            .application { color: red; }
            CSS];
    }

    public function testMalformedFragmentIsRejected(): void
    {
        $this->expectException(InstallerConflict::class);

        (new CssFragmentMerger())->merge('', <<<'CSS'
            @import "tailwindcss";
            /* <te4g-user name="shadcn"> */
            unclosed
            CSS);
    }
}
