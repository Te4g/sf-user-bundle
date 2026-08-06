<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class VerifiedAccountSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const array ALLOWED_ROUTES = [
        'app_check_email',
        'app_verify_email',
        'app_verify_resend',
        'app_login_link_check',
        'app_locale',
        'app_logout',
    ];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', -10]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isVerified()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (is_string($route) && in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_check_email')));
    }
}
