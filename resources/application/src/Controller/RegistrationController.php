<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountRequestLimiter;
use App\Account\RegistrationChallenge;
use App\Account\RegistrationService;
use App\Form\Model\RegistrationInput;
use App\Form\RegistrationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly AccountRequestLimiter $requestLimiter,
        private readonly Security $security,
        private readonly RegistrationChallenge $challenge,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $input = new RegistrationInput();
        $form = $this->createForm(RegistrationType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->requestLimiter->registration($request, $input->email)) {
                return $this->redirectToRoute('app_check_email');
            }

            if (!$this->challenge->verify($form->get('botChallenge')->getData(), $request->getClientIp())) {
                $form->addError(new FormError($this->translator->trans('registration.challenge_failed')));
            } else {
                $user = $this->registration->register($input->name, $input->email, $input->plainPassword);
                if (null !== $user) {
                    $this->security->login($user, 'form_login', 'main');
                }

                return $this->redirectToRoute('app_check_email');
            }
        }

        return $this->render('registration/register.html.twig', [
            'form' => $form,
            'bot_challenge_enabled' => $this->challenge->isEnabled(),
            'bot_challenge_site_key' => $this->challenge->getSiteKey(),
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }
}
