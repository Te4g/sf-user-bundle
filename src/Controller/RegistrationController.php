<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Form\Model\RegistrationInput;
use Te4g\UserBundle\Form\RegistrationType;
use Te4g\UserBundle\Manager\RegistrationManager;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly RegistrationManager $registrationManager,
        private readonly AccountRequestLimiter $requestLimiter,
        private readonly UserBundleOptions $options,
    ) {
    }

    public function register(Request $request): Response
    {
        $input = new RegistrationInput();
        $form = $this->createForm(RegistrationType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->requestLimiter->accept($request, $input->email)) {
                $this->registrationManager->register($input->email, $input->plainPassword);
            }

            return $this->redirectToRoute('te4g_user_check_email');
        }

        return $this->render($this->options->templates['registration'], [
            'form' => $form,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }
}
