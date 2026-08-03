<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Exception\PasswordResetFailed;
use Te4g\UserBundle\Form\EmailType;
use Te4g\UserBundle\Form\Model\EmailInput;
use Te4g\UserBundle\Form\Model\ResetPasswordInput;
use Te4g\UserBundle\Form\ResetPasswordType;
use Te4g\UserBundle\Manager\PasswordResetManager;

final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetManager $passwordResetManager,
        private readonly AccountRequestLimiter $requestLimiter,
        private readonly UserBundleOptions $options,
    ) {
    }

    public function request(Request $request): Response
    {
        $input = new EmailInput();
        $form = $this->createForm(EmailType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->requestLimiter->accept($request, $input->email)) {
                try {
                    $this->passwordResetManager->request($input->email);
                } catch (PasswordResetFailed) {
                    // Preserve the neutral response for known and unknown accounts.
                }
            }

            return $this->redirectToRoute('te4g_user_check_email');
        }

        return $this->render($this->options->templates['password_request'], [
            'form' => $form,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    public function reset(Request $request, string $token): Response
    {
        $input = new ResetPasswordInput();
        $form = $this->createForm(ResetPasswordType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->passwordResetManager->reset($token, $input->plainPassword);

                return $this->redirectToRoute('te4g_user_login');
            } catch (PasswordResetFailed) {
                return $this->render($this->options->templates['password_reset'], [
                    'form' => $form,
                    'reset_failed' => true,
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }
        }

        return $this->render($this->options->templates['password_reset'], [
            'form' => $form,
            'reset_failed' => false,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }
}
