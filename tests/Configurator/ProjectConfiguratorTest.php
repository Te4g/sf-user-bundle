<?php

declare(strict_types=1);

namespace Acme\Bundle\TemplateBundle\Tests\Configurator;

use PHPUnit\Framework\TestCase;
use SymfonyBundleTemplate\Configurator\BundleNames;
use SymfonyBundleTemplate\Configurator\ProjectConfigurator;

final class ProjectConfiguratorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/symfony-bundle-template-'.bin2hex(random_bytes(6));

        mkdir($this->projectDir, 0777, true);

        foreach ([
            'config/services.php',
            'README.md',
            'src/DependencyInjection/Configuration.php',
            'src/DependencyInjection/TemplateExtension.php',
            'src/Service/ExampleService.php',
            'src/TemplateBundle.php',
            'tests/DependencyInjection/ConfigurationTest.php',
            'tests/Fixtures/App/TestKernel.php',
            'tests/Integration/BundleKernelTest.php',
            'tests/TemplateBundleTest.php',
        ] as $file) {
            $this->copyProjectFile($file);
        }

        mkdir($this->projectDir.'/tests/Configurator', 0777, true);
        file_put_contents($this->projectDir.'/tests/Configurator/ProjectConfiguratorTest.php', '<?php'.PHP_EOL);
        file_put_contents($this->projectDir.'/tests/Configurator/BundleNamesTest.php', '<?php'.PHP_EOL);

        copy(dirname(__DIR__, 2).'/composer.json', $this->projectDir.'/composer.json');
        file_put_contents(
            $this->projectDir.'/README.md',
            PHP_EOL.'Install acme/bundle-template for Acme\Bundle\TemplateBundle\TemplateBundle.'.PHP_EOL,
            FILE_APPEND,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testConfiguresProjectForBundleNames(): void
    {
        (new ProjectConfigurator())->configure(
            $this->projectDir,
            BundleNames::fromNamespace('Teag\Bundle\InvoiceBundle'),
        );

        self::assertFileExists($this->projectDir.'/src/InvoiceBundle.php');
        self::assertFileDoesNotExist($this->projectDir.'/src/TemplateBundle.php');
        self::assertFileExists($this->projectDir.'/src/DependencyInjection/InvoiceExtension.php');
        self::assertFileDoesNotExist($this->projectDir.'/src/DependencyInjection/TemplateExtension.php');

        $bundle = $this->readProjectFile('src/InvoiceBundle.php');
        self::assertStringContainsString('namespace Teag\Bundle\InvoiceBundle;', $bundle);
        self::assertStringContainsString('final class InvoiceBundle extends Bundle', $bundle);
        self::assertStringNotContainsString('Acme\Bundle\TemplateBundle', $bundle);
        self::assertStringNotContainsString('TemplateBundle', $bundle);

        $extension = $this->readProjectFile('src/DependencyInjection/InvoiceExtension.php');
        self::assertStringContainsString('namespace Teag\Bundle\InvoiceBundle\DependencyInjection;', $extension);
        self::assertStringContainsString('final class InvoiceExtension extends Extension', $extension);
        self::assertStringContainsString('$container->setParameter(\'invoice.message\', $config[\'message\']);', $extension);
        self::assertStringContainsString('return \'invoice\';', $extension);
        self::assertStringNotContainsString('TemplateExtension', $extension);
        self::assertStringNotContainsString('template.message', $extension);

        $configuration = $this->readProjectFile('src/DependencyInjection/Configuration.php');
        self::assertStringContainsString('namespace Teag\Bundle\InvoiceBundle\DependencyInjection;', $configuration);
        self::assertStringContainsString('$treeBuilder = new TreeBuilder(\'invoice\');', $configuration);

        $services = $this->readProjectFile('config/services.php');
        self::assertStringContainsString('use Teag\Bundle\InvoiceBundle\Service\ExampleService;', $services);
        self::assertStringContainsString('param(\'invoice.message\')', $services);
        self::assertStringNotContainsString('template.message', $services);
        $this->assertStringOccursBefore(
            'use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;',
            'use Teag\Bundle\InvoiceBundle\Service\ExampleService;',
            $services,
        );
        $this->assertStringOccursBefore(
            'use function Symfony\Component\DependencyInjection\Loader\Configurator\param;',
            'use Teag\Bundle\InvoiceBundle\Service\ExampleService;',
            $services,
        );

        self::assertFileExists($this->projectDir.'/tests/InvoiceBundleTest.php');
        self::assertFileDoesNotExist($this->projectDir.'/tests/TemplateBundleTest.php');

        $test = $this->readProjectFile('tests/InvoiceBundleTest.php');
        self::assertStringContainsString('namespace Teag\Bundle\InvoiceBundle\Tests;', $test);
        self::assertStringContainsString('use Teag\Bundle\InvoiceBundle\InvoiceBundle;', $test);
        self::assertStringContainsString('final class InvoiceBundleTest extends TestCase', $test);
        self::assertStringContainsString('new InvoiceBundle()', $test);
        $this->assertStringOccursBefore(
            'use PHPUnit\Framework\TestCase;',
            'use Teag\Bundle\InvoiceBundle\InvoiceBundle;',
            $test,
        );

        self::assertDirectoryDoesNotExist($this->projectDir.'/tests/Configurator');

        $kernel = $this->readProjectFile('tests/Fixtures/App/TestKernel.php');
        self::assertStringContainsString('namespace Teag\Bundle\InvoiceBundle\Tests\Fixtures\App;', $kernel);
        self::assertStringContainsString('use Teag\Bundle\InvoiceBundle\InvoiceBundle;', $kernel);
        self::assertStringContainsString('use Teag\Bundle\InvoiceBundle\Service\ExampleService;', $kernel);
        self::assertStringContainsString('new InvoiceBundle()', $kernel);
        self::assertStringContainsString('$container->loadFromExtension(\'invoice\', $templateConfig);', $kernel);
        $this->assertStringOccursBefore(
            'use Symfony\Component\Config\Loader\LoaderInterface;',
            'use Teag\Bundle\InvoiceBundle\InvoiceBundle;',
            $kernel,
        );

        $integrationTest = $this->readProjectFile('tests/Integration/BundleKernelTest.php');
        self::assertStringContainsString('namespace Teag\Bundle\InvoiceBundle\Tests\Integration;', $integrationTest);
        self::assertStringContainsString('use Teag\Bundle\InvoiceBundle\Service\ExampleService;', $integrationTest);
        self::assertStringContainsString('use Teag\Bundle\InvoiceBundle\Tests\Fixtures\App\TestKernel;', $integrationTest);

        $readme = $this->readProjectFile('README.md');
        self::assertStringContainsString('# InvoiceBundle', $readme);
        self::assertStringContainsString('Package: `teag/invoice-bundle`', $readme);
        self::assertStringContainsString('Bundle namespace: `Teag\Bundle\InvoiceBundle`', $readme);
        self::assertStringContainsString('Bundle alias: `invoice`', $readme);
        self::assertStringContainsString('composer require teag/invoice-bundle', $readme);
        self::assertStringContainsString('make test', $readme);
        self::assertStringContainsString('## Publish To Packagist', $readme);
        self::assertStringContainsString('git tag v1.0.0', $readme);
        self::assertStringContainsString('https://packagist.org/packages/new', $readme);
        self::assertStringContainsString('## Test In A Symfony Application', $readme);
        self::assertStringContainsString('composer require teag/invoice-bundle:@dev', $readme);
        self::assertStringContainsString('composer config repositories.local-bundle path ../path/to/your-bundle', $readme);
        self::assertStringContainsString('Teag\\Bundle\\InvoiceBundle\\InvoiceBundle::class', $readme);
        self::assertStringContainsString('php bin/console debug:config invoice', $readme);
        self::assertStringContainsString('bin/console debug:container', $readme);
        self::assertStringNotContainsString('Start From The GitHub Template', $readme);
        self::assertStringNotContainsString('make rename', $readme);
        self::assertStringNotContainsString('What Rename Updates', $readme);
        self::assertStringNotContainsString('acme/bundle-template', $readme);
        self::assertStringNotContainsString('Acme\Bundle\TemplateBundle', $readme);

        $composer = $this->readComposer();
        self::assertSame('teag/invoice-bundle', $composer['name']);
        self::assertSame(['Teag\Bundle\InvoiceBundle\\' => 'src/'], $composer['autoload']['psr-4']);
        self::assertSame('tests/', $composer['autoload-dev']['psr-4']['Teag\Bundle\InvoiceBundle\Tests\\']);
        self::assertSame('tools/Configurator/', $composer['autoload-dev']['psr-4']['SymfonyBundleTemplate\Configurator\\']);
    }

    public function testMissingReadmeThrowsBeforeWriting(): void
    {
        unlink($this->projectDir.'/README.md');

        $originalBundle = $this->readProjectFile('src/TemplateBundle.php');
        $originalComposer = $this->readProjectFile('composer.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required template file is missing: README.md');

        try {
            (new ProjectConfigurator())->configure(
                $this->projectDir,
                BundleNames::fromNamespace('Teag\Bundle\InvoiceBundle'),
            );
        } finally {
            self::assertSame($originalBundle, $this->readProjectFile('src/TemplateBundle.php'));
            self::assertSame($originalComposer, $this->readProjectFile('composer.json'));
            self::assertFileDoesNotExist($this->projectDir.'/src/InvoiceBundle.php');
        }
    }

    private function copyProjectFile(string $file): void
    {
        $target = $this->projectDir.'/'.$file;
        $targetDir = dirname($target);

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        copy(dirname(__DIR__, 2).'/'.$file, $target);
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposer(): array
    {
        $composer = json_decode($this->readProjectFile('composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);

        return $composer;
    }

    private function readProjectFile(string $file): string
    {
        $contents = file_get_contents($this->projectDir.'/'.$file);

        self::assertIsString($contents);

        return $contents;
    }

    private function assertStringOccursBefore(string $first, string $second, string $contents): void
    {
        $firstPosition = strpos($contents, $first);
        $secondPosition = strpos($contents, $second);

        self::assertIsInt($firstPosition);
        self::assertIsInt($secondPosition);
        self::assertLessThan($secondPosition, $firstPosition);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        self::assertIsArray($items);

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
