<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class LoginLinkCsrfSubscriber implements EventSubscriberInterface
{
    private const int PRIORITY = 16;
    private const string ROUTE = 'te4g_user_login_link_check';

    public function __construct(private CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod(Request::METHOD_POST) || self::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $submittedToken = $request->request->all()['_csrf_token'] ?? null;
        if (!is_string($submittedToken)
            || '' === $submittedToken
            || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::ROUTE, $submittedToken))
        ) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY],
        ];
    }
}
