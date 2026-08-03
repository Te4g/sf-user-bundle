<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Exception\CurrentPasswordInvalid;
use Te4g\UserBundle\Exception\EmailChangeFailed;
use Te4g\UserBundle\Form\Model\ProfileInput;
use Te4g\UserBundle\Form\ProfileType;
use Te4g\UserBundle\Manager\ProfileManager;

final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly ProfileManager $profileManager,
        private readonly UserBundleOptions $options,
    ) {
    }

    public function profile(Request $request): Response
    {
        $account = $this->account();
        $input = ProfileInput::fromAccount($account);
        $form = $this->createForm(ProfileType::class, $input);
        $form->handleRequest($request);
        $emailChangeFailed = false;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->profileManager->requestEmailChange($account, $input->email, $input->currentPassword);

                return $this->redirectToRoute($this->options->redirects['account_updated']);
            } catch (CurrentPasswordInvalid|EmailChangeFailed) {
                $emailChangeFailed = true;
            }
        }

        return $this->renderProfile(
            $account,
            $form,
            $emailChangeFailed,
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    public function confirmEmailChange(Request $request): Response
    {
        $account = $this->account();
        $accountId = (string) $request->query->get('id', '');

        if ((string) $account->getId() === $accountId) {
            try {
                $this->profileManager->confirmEmailChange($request->getUri(), $accountId);

                return $this->redirectToRoute($this->options->redirects['account_updated']);
            } catch (EmailChangeFailed) {
            }
        }

        return $this->renderProfile(
            $account,
            $this->createForm(ProfileType::class, ProfileInput::fromAccount($account)),
            true,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function renderProfile(AccountUserInterface $account, FormInterface $form, bool $emailChangeFailed, int $status): Response
    {
        return $this->render($this->options->templates['profile'], [
            'account' => $account,
            'form' => $form,
            'email_change_failed' => $emailChangeFailed,
        ], new Response(status: $status));
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
