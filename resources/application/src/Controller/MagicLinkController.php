<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountRequestLimiter;
use App\Account\MagicLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final class MagicLinkController extends AbstractController
{
    public function __construct(
        private readonly MagicLinkService $magicLink,
        private readonly AccountRequestLimiter $requestLimiter,
    ) {
    }

    #[Route('/login-link', name: 'app_login_link_request', methods: ['POST'])]
    public function request(Request $request): Response
    {
        $form = $this->emailForm();
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('security/login.html.twig', [
                'last_username' => '',
                'authentication_failed' => false,
                'magic_link_form' => $form->createView(),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $data = $form->getData();
        $email = is_array($data) && is_string($data['email'] ?? null) ? $data['email'] : '';
        if ($this->requestLimiter->magicLink($request, $email)) {
            $this->magicLink->send($email);
        }

        return $this->redirectToRoute('app_check_email');
    }

    #[Route('/login-link/check', name: 'app_login_link_check', methods: ['GET', 'POST'])]
    public function check(Request $request): Response
    {
        if ($request->isMethod(Request::METHOD_POST)) {
            return $this->redirectToRoute('app_login');
        }

        $parameters = [];
        foreach (['expires', 'user', 'hash'] as $name) {
            $value = $request->query->get($name);
            if (is_scalar($value)) {
                $parameters[$name] = (string) $value;
            }
        }

        return $this->render('security/login_link_check.html.twig', [
            'login_link_parameters' => $parameters,
        ]);
    }

    private function emailForm(): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder(['email' => ''], [
            'action' => $this->generateUrl('app_login_link_request'),
            'method' => Request::METHOD_POST,
        ])->add('email', EmailType::class, [
            'attr' => ['autocomplete' => 'email'],
            'constraints' => [new Assert\NotBlank(), new Assert\Email()],
        ])->getForm();
    }
}
