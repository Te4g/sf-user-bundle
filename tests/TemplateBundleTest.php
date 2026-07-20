<?php

declare(strict_types=1);

namespace Acme\Bundle\TemplateBundle\Tests;

use Acme\Bundle\TemplateBundle\TemplateBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class TemplateBundleTest extends TestCase
{
    public function testBundleInstantiates(): void
    {
        self::assertInstanceOf(Bundle::class, new TemplateBundle());
    }
}
