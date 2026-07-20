<?php

declare(strict_types=1);

namespace Acme\Bundle\TemplateBundle\Tests\Configurator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymfonyBundleTemplate\Configurator\BundleNames;

final class BundleNamesTest extends TestCase
{
    public function testNamesAreDerivedFromBundleNamespace(): void
    {
        $names = BundleNames::fromNamespace('Teag\Bundle\InvoiceBundle');

        self::assertSame('Teag\Bundle\InvoiceBundle', $names->namespace);
        self::assertSame('Teag\Bundle\InvoiceBundle\\', $names->namespacePrefix);
        self::assertSame('Teag\Bundle\InvoiceBundle\Tests', $names->testNamespace);
        self::assertSame('Teag\Bundle\InvoiceBundle\Tests\\', $names->testNamespacePrefix);
        self::assertSame('InvoiceBundle', $names->bundleClass);
        self::assertSame('InvoiceExtension', $names->extensionClass);
        self::assertSame('invoice', $names->alias);
        self::assertSame('invoice.message', $names->messageParameter);
        self::assertSame('teag/invoice-bundle', $names->packageName);
    }

    public function testCompoundBundleNameUsesSnakeCaseAliasAndKebabPackage(): void
    {
        $names = BundleNames::fromNamespace('Acme\Bundle\BlogPostBundle');

        self::assertSame('blog_post', $names->alias);
        self::assertSame('blog_post.message', $names->messageParameter);
        self::assertSame('acme/blog-post-bundle', $names->packageName);
        self::assertSame('BlogPostExtension', $names->extensionClass);
    }

    public function testAcronymBundleNameUsesSymfonyStyleAlias(): void
    {
        $names = BundleNames::fromNamespace('Acme\Bundle\XMLParserBundle');

        self::assertSame('xml_parser', $names->alias);
        self::assertSame('xml_parser.message', $names->messageParameter);
        self::assertSame('acme/xml-parser-bundle', $names->packageName);
        self::assertSame('XMLParserExtension', $names->extensionClass);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNamespaces(): iterable
    {
        yield 'empty' => [''];
        yield 'no namespace separator' => ['InvoiceBundle'];
        yield 'lowercase segment' => ['teag\Bundle\InvoiceBundle'];
        yield 'does not end with bundle class' => ['Teag\Bundle\Invoice'];
        yield 'invalid character' => ['Teag\Bundle\Invoice-Bundle'];
        yield 'empty segment' => ['Teag\\\InvoiceBundle'];
        yield 'empty bundle base name' => ['Teag\Bundle\Bundle'];
    }

    #[DataProvider('invalidNamespaces')]
    public function testInvalidNamespacesAreRejected(string $namespace): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BundleNames::fromNamespace($namespace);
    }
}
