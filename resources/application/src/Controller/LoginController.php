<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Constraints as Assert;

final class LoginController extends AbstractController
{
    public function __construct(private readonly AuthenticationUtils $authenticationUtils)
    {
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(): Response
    {
        $error = $this->authenticationUtils->getLastAuthenticationError();

        return $this->render('security/login.html.twig', [
            'last_username' => $this->authenticationUtils->getLastUsername(),
            'authentication_failed' => null !== $error,
            'magic_link_form' => $this->emailForm()->createView(),
        ], null !== $error ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall must intercept the logout route.');
    }

    private function emailForm(): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder(['email' => ''], [
            'action' => $this->generateUrl('app_login_link_request'),
            'method' => Request::METHOD_POST,
        ])->add('email', EmailType::class, [
            'label' => 'form.email',
            'attr' => ['autocomplete' => 'email'],
            'constraints' => [new Assert\NotBlank(), new Assert\Email()],
        ])->getForm();
    }
}
