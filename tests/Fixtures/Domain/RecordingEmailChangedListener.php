<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

use Te4g\UserBundle\Event\EmailChangedEvent;

final class RecordingEmailChangedListener
{
    /** @var list<EmailChangedEvent> */
    public array $events = [];

    public function __invoke(EmailChangedEvent $event): void
    {
        $this->events[] = $event;
    }

    public function clearActivity(): void
    {
        $this->events = [];
    }
}
