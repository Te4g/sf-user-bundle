<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Locale\SupportedLocales;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class LocaleController
{
    public function __construct(
        private SupportedLocales $locales,
        private CsrfTokenManagerInterface $csrf,
        private Security $security,
        private UserRepository $users,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/locale', name: 'app_locale', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('locale_change', $request->request->getString('_token')))) {
            return new Response(status: Response::HTTP_FORBIDDEN);
        }

        $locale = $request->request->getString('locale');
        if (!$this->locales->supports($locale)) {
            return new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->security->getUser();
        $authenticated = $user instanceof User;
        if ($authenticated) {
            $user->setLocale($locale);
            $this->users->save($user);
        }

        $response = new RedirectResponse($this->returnTarget(
            $request->request->getString('return_to'),
            $authenticated,
        ));
        $response->headers->setCookie(
            Cookie::create('app_locale')
                ->withValue($locale)
                ->withExpires(new \DateTimeImmutable('+1 year'))
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        return $response;
    }

    private function returnTarget(string $target, bool $authenticated): string
    {
        if (str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_contains($target, '\\')
            && 1 !== preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return $target;
        }

        return $this->urlGenerator->generate($authenticated ? 'app_dashboard' : 'app_home');
    }
}
