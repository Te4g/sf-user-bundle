<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\PasswordService;
use App\Entity\User;
use App\Form\Model\PasswordChangeInput;
use App\Form\PasswordChangeType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PasswordController extends AbstractController
{
    public function __construct(
        private readonly PasswordService $password,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/password', name: 'app_account_password', methods: ['GET', 'POST'])]
    public function change(Request $request): Response
    {
        $user = $this->account();
        $input = new PasswordChangeInput();
        $form = $this->createForm(PasswordChangeType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->password->change($user, $input->currentPassword, $input->plainPassword);

                return $this->redirectToRoute('app_account');
            } catch (\RuntimeException) {
                $form->addError(new FormError($this->translator->trans('password_change.failed')));
            }
        }

        return $this->render('account/password.html.twig', [
            'form' => $form->createView(),
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    private function account(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('An authenticated account is required.');
        }

        return $user;
    }
}
