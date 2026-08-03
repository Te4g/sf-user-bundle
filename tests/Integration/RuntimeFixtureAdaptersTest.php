<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Tests\Fixtures\Domain\FakeAccountUserFactory;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;

final class RuntimeFixtureAdaptersTest extends TestCase
{
    public function testRepositoryFindsSavedUserByNormalizedEmailAndStringIdentifier(): void
    {
        $factory = new FakeAccountUserFactory();
        $user = $factory->create('USER@Example.TEST');
        $repository = new InMemoryAccountRepository();

        $repository->save($user);

        self::assertSame($user, $repository->findOneByEmail(' user@example.test '));
        self::assertSame($user, $repository->findById('1'));
        self::assertSame([$user], $repository->saved);
    }

    public function testRepositoryRecordsRemovalAndCanSimulatePersistenceFailures(): void
    {
        $factory = new FakeAccountUserFactory();
        $user = $factory->create('user@example.test');
        $repository = new InMemoryAccountRepository();
        $repository->save($user);
        $repository->remove($user);

        self::assertNull($repository->findOneByEmail('user@example.test'));
        self::assertSame([$user], $repository->removed);

        $failingRepository = new InMemoryAccountRepository(failOnSave: true, failOnRemove: true);

        $this->expectException(\RuntimeException::class);
        $failingRepository->save($user);
    }

    public function testFactoryAssignsIncrementingIntegerIdentifiers(): void
    {
        $factory = new FakeAccountUserFactory();

        self::assertSame(1, $factory->create('first@example.test')->getId());
        self::assertSame(2, $factory->create('second@example.test')->getId());
    }

    public function testRepositoryCanSimulateRemovalFailure(): void
    {
        $user = (new FakeAccountUserFactory())->create('user@example.test');
        $repository = new InMemoryAccountRepository(failOnRemove: true);

        $this->expectException(\RuntimeException::class);
        $repository->remove($user);
    }

    public function testMailerRecordsTypedMessagesWithoutSendingThem(): void
    {
        $user = (new FakeAccountUserFactory())->create('user@example.test');
        $mailer = new RecordingAccountMailer();

        $mailer->sendVerification($user, 'https://example.test/verify');

        self::assertSame([[
            'type' => 'verification',
            'user' => $user,
            'absoluteUrl' => 'https://example.test/verify',
        ]], $mailer->messages);
    }
}
