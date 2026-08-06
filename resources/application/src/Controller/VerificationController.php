<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountRequestLimiter;
use App\Account\EmailNormalizer;
use App\Account\EmailVerificationService;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final class VerificationController extends AbstractController
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly UserRepository $users,
        private readonly EmailNormalizer $normalizer,
        private readonly AccountRequestLimiter $requestLimiter,
    ) {
    }

    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        $context = [];
        $user = $this->getUser();
        if ($user instanceof User && !$user->isVerified()) {
            $context['resend_form'] = $this->emailForm('app_verify_resend')->createView();
        }

        return $this->render('security/check_email.html.twig', $context);
    }

    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $id = $request->query->get('id');
        if (!is_string($id) || !ctype_digit($id)) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $this->verification->verify($request->getUri(), (int) $id);
        } catch (\RuntimeException) {
            return $this->redirectToRoute('app_login');
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/verify/email/resend', name: 'app_verify_resend', methods: ['POST'])]
    public function resend(Request $request): Response
    {
        $form = $this->emailForm('app_verify_resend');
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('security/check_email.html.twig', [
                'resend_form' => $form->createView(),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $data = $form->getData();
        $email = is_array($data) && is_string($data['email'] ?? null) ? $data['email'] : '';
        if ($this->requestLimiter->verificationResend($request, $email)) {
            $user = $this->users->findOneByNormalizedEmail($this->normalizer->normalize($email));
            if ($user instanceof User && !$user->isVerified()) {
                $this->verification->send($user);
            }
        }

        return $this->redirectToRoute('app_check_email');
    }

    private function emailForm(string $routeName): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder(['email' => ''], [
            'action' => $this->generateUrl($routeName),
            'method' => Request::METHOD_POST,
        ])->add('email', EmailType::class, [
            'label' => 'form.email',
            'attr' => ['autocomplete' => 'email'],
            'constraints' => [new Assert\NotBlank(), new Assert\Email()],
        ])->getForm();
    }
}
