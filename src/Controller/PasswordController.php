<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Exception\CurrentPasswordInvalid;
use Te4g\UserBundle\Form\Model\PasswordChangeInput;
use Te4g\UserBundle\Form\PasswordChangeType;
use Te4g\UserBundle\Manager\PasswordManager;

final class PasswordController extends AbstractController
{
    public function __construct(
        private readonly PasswordManager $passwordManager,
        private readonly UserBundleOptions $options,
    ) {
    }

    public function change(Request $request): Response
    {
        $account = $this->account();
        $input = new PasswordChangeInput();
        $form = $this->createForm(PasswordChangeType::class, $input);
        $form->handleRequest($request);
        $passwordChangeFailed = false;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->passwordManager->change($account, $input->currentPassword, $input->plainPassword);

                return $this->redirectToRoute($this->options->redirects['account_updated']);
            } catch (CurrentPasswordInvalid) {
                $passwordChangeFailed = true;
            }
        }

        return $this->render($this->options->templates['password_change'], [
            'form' => $form,
            'password_change_failed' => $passwordChangeFailed,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
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
