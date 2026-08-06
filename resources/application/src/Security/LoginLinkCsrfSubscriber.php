<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class LoginLinkCsrfSubscriber implements EventSubscriberInterface
{
    public function __construct(private CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod(Request::METHOD_POST) || 'app_login_link_check' !== $request->attributes->get('_route')) {
            return;
        }

        $submittedToken = $request->request->get('_csrf_token');
        if (!is_string($submittedToken)
            || !$this->csrfTokenManager->isTokenValid(new CsrfToken('app_login_link_check', $submittedToken))
        ) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }
}
