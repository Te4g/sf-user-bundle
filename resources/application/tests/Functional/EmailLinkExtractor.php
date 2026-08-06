<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

final class EmailLinkExtractor
{
    public static function from(Email $email, string $expectedHost, string $expectedScheme): string
    {
        $html = self::body($email->getHtmlBody());
        if ('' !== $html) {
            $links = (new Crawler($html))->filter('a[href]');
            if (1 !== $links->count()) {
                throw new \RuntimeException('Expected exactly one link in the rendered account email.');
            }

            $href = $links->attr('href');
            if (!is_string($href)) {
                throw new \RuntimeException('The rendered account email link has no href.');
            }

            $url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $text = self::body($email->getTextBody());
            if (1 !== preg_match('~https?://[^\s<>"\']+~', $text, $matches)) {
                throw new \RuntimeException('Expected one absolute link in the rendered account email.');
            }

            $url = html_entity_decode($matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || $expectedScheme !== ($parts['scheme'] ?? null)
            || $expectedHost !== ($parts['host'] ?? null)
        ) {
            throw new \RuntimeException('The rendered account email link has an unexpected origin.');
        }

        return $url;
    }

    /** @param resource|string|null $body */
    private static function body(mixed $body): string
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body);

            return false === $contents ? '' : $contents;
        }

        return is_string($body) ? $body : '';
    }
}
