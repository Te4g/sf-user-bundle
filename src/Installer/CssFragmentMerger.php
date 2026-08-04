<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;

final readonly class CssFragmentMerger
{
    private const string CLOSE = '/* </te4g-user> */';
    private const string OPEN = '/* <te4g-user name="shadcn"> */';

    public function __construct(private MarkerEditor $markerEditor = new MarkerEditor())
    {
    }

    public function merge(string $stylesheet, string $fragment): string
    {
        [$requiredImports, $body] = $this->parseFragment($fragment);
        $stylesheet = $this->mergeImports($stylesheet, $requiredImports);

        return $this->markerEditor->upsert($stylesheet, 'shadcn', $body);
    }

    /** @return array{list<array{source: string, statement: string}>, string} */
    private function parseFragment(string $fragment): array
    {
        if (1 !== substr_count($fragment, self::OPEN) || 1 !== substr_count($fragment, self::CLOSE)) {
            throw InstallerConflict::invalidMarker();
        }

        $open = strpos($fragment, self::OPEN);
        $close = strpos($fragment, self::CLOSE);
        if (false === $open || false === $close || $close < $open || '' !== trim(substr($fragment, $close + strlen(self::CLOSE)))) {
            throw InstallerConflict::invalidMarker();
        }

        $imports = $this->parseImportPreamble(substr($fragment, 0, $open), true);
        $body = trim(substr($fragment, $open + strlen(self::OPEN), $close - $open - strlen(self::OPEN)), "\r\n");
        if ('' === $body || str_contains($body, '/* <te4g-user') || str_contains($body, '/* </te4g-user')) {
            throw InstallerConflict::invalidMarker();
        }

        return [$imports['imports'], $body];
    }

    /** @param list<array{source: string, statement: string}> $requiredImports */
    private function mergeImports(string $stylesheet, array $requiredImports): string
    {
        $preamble = $this->parseImportPreamble($stylesheet, false);
        $existingBySource = [];
        foreach ($preamble['imports'] as $import) {
            if (isset($existingBySource[$import['source']])) {
                throw InstallerConflict::invalidMarker();
            }
            $existingBySource[$import['source']] = $import['statement'];
        }

        $missing = [];
        foreach ($requiredImports as $import) {
            if (isset($existingBySource[$import['source']])) {
                if ($existingBySource[$import['source']] !== $import['statement']) {
                    throw InstallerConflict::invalidMarker();
                }

                continue;
            }
            $missing[] = $import['statement'];
        }

        if ([] === $missing) {
            return $stylesheet;
        }

        $prefix = substr($stylesheet, 0, $preamble['insertion']);
        $suffix = substr($stylesheet, $preamble['insertion']);
        $before = '' === $prefix || preg_match('/\s\z/', $prefix) ? '' : "\n";
        $after = '' === $suffix || preg_match('/\A\s/', $suffix) ? '' : "\n\n";

        return $prefix.$before.implode("\n", $missing).$after.$suffix;
    }

    /**
     * @return array{imports: list<array{source: string, statement: string}>, insertion: int}
     */
    private function parseImportPreamble(string $contents, bool $importsOnly): array
    {
        $importPattern = '~\G@import\s+(?:"(?<double>[^"]+)"|\'(?<single>[^\']+)\')(?<qualifiers>[^;]*);~Ai';
        $imports = [];
        $seen = [];
        $cursor = 0;
        $insertion = 0;
        $length = strlen($contents);
        while ($cursor < $length) {
            if (preg_match('/\G\s+/A', $contents, $whitespace, 0, $cursor)) {
                $cursor += strlen($whitespace[0]);

                continue;
            }
            if (preg_match('~\G/\*.*?\*/~As', $contents, $comment, 0, $cursor)) {
                $cursor += strlen($comment[0]);
                $insertion = $cursor;

                continue;
            }
            if (preg_match('/\G@charset\s+[^;]+;/Ai', $contents, $charset, 0, $cursor)) {
                $cursor += strlen($charset[0]);
                $insertion = $cursor;

                continue;
            }
            if (preg_match($importPattern, $contents, $import, 0, $cursor)) {
                $statement = $import[0];
                $source = '' !== $import['double'] ? $import['double'] : $import['single'];
                if (isset($seen[$source])) {
                    throw InstallerConflict::invalidMarker();
                }
                $seen[$source] = true;
                $imports[] = ['source' => $source, 'statement' => $statement];
                $cursor += strlen($statement);
                $insertion = $cursor;

                continue;
            }

            break;
        }

        if ($this->containsTopLevelImport($contents, $cursor)) {
            throw InstallerConflict::invalidMarker();
        }
        if ($importsOnly && '' !== trim(substr($contents, $cursor))) {
            throw InstallerConflict::invalidMarker();
        }

        return ['imports' => $imports, 'insertion' => $insertion];
    }

    private function containsTopLevelImport(string $contents, int $cursor): bool
    {
        $length = strlen($contents);
        $depth = 0;
        $quote = null;
        for (; $cursor < $length; ++$cursor) {
            $character = $contents[$cursor];
            if (null !== $quote) {
                if ('\\' === $character) {
                    ++$cursor;
                } elseif ($quote === $character) {
                    $quote = null;
                }

                continue;
            }
            if ('/' === $character && '*' === ($contents[$cursor + 1] ?? null)) {
                $end = strpos($contents, '*/', $cursor + 2);
                if (false === $end) {
                    return false;
                }
                $cursor = $end + 1;

                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;

                continue;
            }
            if ('{' === $character) {
                ++$depth;

                continue;
            }
            if ('}' === $character) {
                $depth = max(0, $depth - 1);

                continue;
            }
            if (0 === $depth && 0 === strncasecmp(substr($contents, $cursor, 7), '@import', 7)) {
                $next = $contents[$cursor + 7] ?? '';
                if ('' === $next || preg_match('/\s|["\'\(]/', $next)) {
                    return true;
                }
            }
        }

        return false;
    }
}
