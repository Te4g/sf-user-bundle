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

        $insertion = 0 === $preamble['insertion']
            ? implode("\n", $missing)."\n\n"
            : "\n".implode("\n", $missing);

        return substr($stylesheet, 0, $preamble['insertion'])
            .$insertion
            .substr($stylesheet, $preamble['insertion']);
    }

    /**
     * @return array{imports: list<array{source: string, statement: string}>, insertion: int}
     */
    private function parseImportPreamble(string $contents, bool $importsOnly): array
    {
        $pattern = '~@import\s+(?:"(?<double>[^"]+)"|\'(?<single>[^\']+)\')(?<qualifiers>[^;]*);~';
        $count = preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (false === $count || substr_count($contents, '@import') !== $count) {
            throw InstallerConflict::invalidMarker();
        }

        $imports = [];
        $seen = [];
        $lastEnd = 0;
        foreach ($matches as $match) {
            $statement = $match[0][0];
            $offset = $match[0][1];
            $source = '' !== $match['double'][0] ? $match['double'][0] : $match['single'][0];
            if (isset($seen[$source])) {
                throw InstallerConflict::invalidMarker();
            }
            $seen[$source] = true;
            $imports[] = ['source' => $source, 'statement' => $statement];
            $lastEnd = $offset + strlen($statement);
        }

        $cursor = 0;
        $length = strlen($contents);
        while ($cursor < $length) {
            if (preg_match('/\G\s+/A', $contents, $whitespace, 0, $cursor)) {
                $cursor += strlen($whitespace[0]);

                continue;
            }
            if (preg_match('~\G/\*.*?\*/~As', $contents, $comment, 0, $cursor)) {
                $cursor += strlen($comment[0]);

                continue;
            }
            if (preg_match('/\G@charset\s+[^;]+;/Ai', $contents, $charset, 0, $cursor)) {
                $cursor += strlen($charset[0]);

                continue;
            }
            if (preg_match($pattern.'A', $contents, $import, 0, $cursor)) {
                $cursor += strlen($import[0]);

                continue;
            }

            break;
        }

        foreach ($matches as $match) {
            if ($match[0][1] >= $cursor) {
                throw InstallerConflict::invalidMarker();
            }
        }
        if ($importsOnly && '' !== trim(substr($contents, $cursor))) {
            throw InstallerConflict::invalidMarker();
        }

        return ['imports' => $imports, 'insertion' => 0 === $count ? $cursor : $lastEnd];
    }
}
