<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;

final class MarkerEditor
{
    private const CLOSE = '/* </te4g-user> */';

    public function upsert(string $contents, string $name, string $generated): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9._-]+$/', $name)
            || str_contains($generated, '/* <te4g-user')
            || str_contains($generated, self::CLOSE)
        ) {
            throw InstallerConflict::invalidMarker();
        }

        $regions = $this->parseRegions($contents);
        $matching = array_values(array_filter(
            $regions,
            static fn (array $region): bool => $region['name'] === $name,
        ));
        if (count($matching) > 1) {
            throw InstallerConflict::invalidMarker();
        }

        $open = sprintf('/* <te4g-user name="%s"> */', $name);
        $body = rtrim($generated, "\r\n");
        $region = $open."\n".$body."\n".self::CLOSE;

        if ([] === $matching) {
            if ('' === $contents) {
                return $region."\n";
            }

            return rtrim($contents, "\r\n")."\n\n".$region."\n";
        }

        $target = $matching[0];

        return substr($contents, 0, $target['start'])
            .$region
            .substr($contents, $target['end']);
    }

    /**
     * @return list<array{name: string, start: int, end: int}>
     */
    private function parseRegions(string $contents): array
    {
        $openPattern = '~/\* <te4g-user name="[A-Za-z0-9._-]+"> \*/~';
        $tokenPattern = '~(/\* <te4g-user name="[A-Za-z0-9._-]+"> \*/)|(/\* </te4g-user> \*/)~';
        $openCount = preg_match_all($openPattern, $contents);
        $closeCount = preg_match_all('~/\* </te4g-user> \*/~', $contents);
        preg_match_all($tokenPattern, $contents, $tokenMatches, PREG_OFFSET_CAPTURE);

        if (false === $openCount
            || false === $closeCount
            || substr_count($contents, '/* <te4g-user') !== $openCount
            || substr_count($contents, '/* </te4g-user') !== $closeCount
        ) {
            throw InstallerConflict::invalidMarker();
        }

        $regions = [];
        $open = null;
        $names = [];
        foreach ($tokenMatches[0] as [$token, $offset]) {
            if (self::CLOSE !== $token) {
                if (null !== $open) {
                    throw InstallerConflict::invalidMarker();
                }

                $markerName = substr($token, strlen('/* <te4g-user name="'), -strlen('"> */'));
                if (isset($names[$markerName])) {
                    throw InstallerConflict::invalidMarker();
                }

                $names[$markerName] = true;
                $open = [
                    'name' => $markerName,
                    'start' => $offset,
                ];

                continue;
            }

            if (null === $open) {
                throw InstallerConflict::invalidMarker();
            }

            $regions[] = [
                'name' => $open['name'],
                'start' => $open['start'],
                'end' => $offset + strlen($token),
            ];
            $open = null;
        }

        if (null !== $open) {
            throw InstallerConflict::invalidMarker();
        }

        return $regions;
    }
}
