<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Util\EmailNormalizer;

final class EmailNormalizerTest extends TestCase
{
    #[DataProvider('emails')]
    public function testEmailNormalization(string $input, string $expected): void
    {
        self::assertSame($expected, (new EmailNormalizer())->normalize($input));
    }

    public static function emails(): iterable
    {
        yield [' User@Example.COM ', 'user@example.com'];
        yield ["\tTEST@example.com\n", 'test@example.com'];
    }
}
