<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountDeletionService;
use App\Entity\User;
use App\Form\DeleteAccountType;
use App\Form\Model\DeleteAccountInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DeleteAccountController extends AbstractController
{
    public function __construct(
        private readonly AccountDeletionService $deletion,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/account/delete', name: 'app_account_delete', methods: ['GET', 'POST'])]
    public function delete(Request $request): Response
    {
        $user = $this->account();
        $input = new DeleteAccountInput();
        $form = $this->createForm(DeleteAccountType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->deletion->delete($user, $input->currentPassword);
                $this->tokenStorage->setToken(null);
                $request->getSession()->invalidate();

                return $this->redirectToRoute('app_home');
            } catch (\RuntimeException) {
                $form->addError(new FormError('Unable to delete your account.'));
            }
        }

        return $this->render('account/delete.html.twig', [
            'account' => $user,
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
