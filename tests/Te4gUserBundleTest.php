<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Te4g\UserBundle\Te4gUserBundle;

final class Te4gUserBundleTest extends TestCase
{
    public function testBundleInstantiates(): void
    {
        self::assertInstanceOf(Bundle::class, new Te4gUserBundle());
    }
}
