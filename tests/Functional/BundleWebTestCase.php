<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Te4g\UserBundle\Tests\Fixtures\App\WebTestKernel;

abstract class BundleWebTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $kernel = self::$kernel;

        parent::tearDown();

        if ($kernel instanceof WebTestKernel) {
            $kernel->cleanup();
        }

        restore_exception_handler();
    }
}
