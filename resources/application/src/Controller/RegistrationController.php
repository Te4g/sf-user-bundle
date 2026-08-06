<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountRequestLimiter;
use App\Account\RegistrationService;
use App\Form\Model\RegistrationInput;
use App\Form\RegistrationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly AccountRequestLimiter $requestLimiter,
        private readonly Security $security,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $input = new RegistrationInput();
        $form = $this->createForm(RegistrationType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->requestLimiter->registration($request, $input->email)) {
                $user = $this->registration->register($input->name, $input->email, $input->plainPassword);
                if (null !== $user) {
                    $this->security->login($user, 'form_login', 'main');
                }
            }

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('registration/register.html.twig', [
            'form' => $form,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }
}
