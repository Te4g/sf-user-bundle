<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Form\EmailType;
use Te4g\UserBundle\Form\Model\EmailInput;

final class LoginController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly FormFactoryInterface $formFactory,
        private readonly UserBundleOptions $options,
    ) {
    }

    public function login(): Response
    {
        $error = $this->authenticationUtils->getLastAuthenticationError();

        return $this->render($this->options->templates['login'], [
            'last_username' => $this->authenticationUtils->getLastUsername(),
            'authentication_failed' => null !== $error,
            'magic_link_form' => $this->formFactory->create(EmailType::class, new EmailInput(), [
                'action' => $this->generateUrl('te4g_user_login_link_request'),
                'method' => Request::METHOD_POST,
            ]),
        ], null !== $error ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    public function checkEmail(): Response
    {
        return $this->render($this->options->templates['check_email'], [
            'resend_form' => $this->formFactory->create(EmailType::class, new EmailInput(), [
                'action' => $this->generateUrl('te4g_user_verify_resend'),
                'method' => Request::METHOD_POST,
            ]),
        ]);
    }

    public function logout(): never
    {
        throw new \LogicException('The firewall must intercept the account logout route.');
    }
}
