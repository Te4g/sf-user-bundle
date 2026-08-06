<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Locale\SupportedLocales;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

final readonly class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private SupportedLocales $locales,
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $user = $this->security->getUser();
        $locale = $this->locales->resolve($request, $user instanceof User ? $user : null);

        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }
}
