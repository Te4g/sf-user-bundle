<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountRequestLimiter;
use App\Account\PasswordResetService;
use App\Form\Model\ResetPasswordInput;
use App\Form\ResetPasswordType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetService $passwordReset,
        private readonly AccountRequestLimiter $requestLimiter,
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $form = $this->emailForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $email = is_array($data) && is_string($data['email'] ?? null) ? $data['email'] : '';
            if ($this->requestLimiter->passwordReset($request, $email)) {
                $this->passwordReset->request($email);
            }

            return $this->redirectToRoute('app_forgot_password_check_email');
        }

        return $this->render('password/request.html.twig', [
            'form' => $form->createView(),
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    #[Route('/forgot-password/check-email', name: 'app_forgot_password_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('security/check_email.html.twig');
    }

    #[Route('/forgot-password/reset/{token}', name: 'app_forgot_password_reset', methods: ['GET', 'POST'])]
    public function reset(Request $request, #[\SensitiveParameter] string $token): Response
    {
        $input = new ResetPasswordInput();
        $form = $this->createForm(ResetPasswordType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->passwordReset->reset($token, $input->plainPassword);

                return $this->redirectToRoute('app_login');
            } catch (\RuntimeException) {
                return $this->render('password/reset.html.twig', [
                    'form' => $form->createView(),
                    'reset_failed' => true,
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }
        }

        return $this->render('password/reset.html.twig', [
            'form' => $form->createView(),
            'reset_failed' => false,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    private function emailForm(): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder(['email' => ''])
            ->add('email', EmailType::class, [
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->getForm()
        ;
    }
}
