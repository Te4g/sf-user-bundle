<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Exception\AccountDeletionRejected;
use Te4g\UserBundle\Exception\CurrentPasswordInvalid;
use Te4g\UserBundle\Form\DeleteAccountType;
use Te4g\UserBundle\Form\Model\DeleteAccountInput;
use Te4g\UserBundle\Manager\AccountDeletionManager;

final class DeleteAccountController extends AbstractController
{
    public function __construct(
        private readonly AccountDeletionManager $accountDeletionManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UserBundleOptions $options,
    ) {
    }

    public function delete(Request $request): Response
    {
        if (!$this->options->deletionEnabled) {
            throw $this->createNotFoundException();
        }

        $account = $this->account();
        $input = new DeleteAccountInput();
        $form = $this->createForm(DeleteAccountType::class, $input, [
            'action' => $this->generateUrl('te4g_user_account_delete'),
        ]);
        $form->handleRequest($request);
        $deletionFailed = false;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->accountDeletionManager->delete($account, $input->currentPassword);
                $this->tokenStorage->setToken(null);
                $request->getSession()->invalidate();

                return $this->redirectToRoute($this->options->redirects['account_deleted']);
            } catch (CurrentPasswordInvalid|AccountDeletionRejected) {
                $deletionFailed = true;
            }
        }

        return $this->render($this->options->templates['account'], [
            'account' => $account,
            'deletion_form' => $form,
            'deletion_failed' => $deletionFailed,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function account(): AccountUserInterface
    {
        $account = $this->getUser();

        if (!$account instanceof AccountUserInterface) {
            throw new AccessDeniedException('An authenticated account is required.');
        }

        return $account;
    }
}
