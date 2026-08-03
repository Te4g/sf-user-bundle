<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\AccountRegisteredEvent;
use Te4g\UserBundle\Event\AccountVerifiedEvent;

final class RecordedEvents extends EventDispatcher
{
    /** @var list<object> */
    private array $events = [];

    public function __construct(private readonly ?OperationTrace $trace = null)
    {
        parent::__construct();
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = $event;

        if ($event instanceof AccountRegisteredEvent) {
            $this->trace?->record('event:registered');
        }

        if ($event instanceof AccountVerifiedEvent) {
            $this->trace?->record('event:verified');
        }

        return parent::dispatch($event, $eventName);
    }

    /**
     * @param class-string<AccountRegisteredEvent|AccountVerifiedEvent> $eventClass
     *
     * @return list<AccountUserInterface>
     */
    public function eventsOf(string $eventClass): array
    {
        $users = [];

        foreach ($this->events as $event) {
            if (!$event instanceof $eventClass) {
                continue;
            }

            $users[] = $event->getUser();
        }

        return $users;
    }
}
