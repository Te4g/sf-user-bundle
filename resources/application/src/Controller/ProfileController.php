<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountRequestLimiter;
use App\Account\ProfileService;
use App\Entity\User;
use App\Form\Model\ProfileInput;
use App\Form\ProfileType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly ProfileService $profile,
        private readonly AccountRequestLimiter $requestLimiter,
    ) {
    }

    #[Route('/account/profile', name: 'app_account_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        $user = $this->account();
        $input = ProfileInput::fromUser($user);
        $form = $this->createForm(ProfileType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if ($input->email !== $user->getEmail()) {
                    if (!$this->requestLimiter->emailChange($request, $input->email)) {
                        throw new \RuntimeException('Account update failed.');
                    }

                    $this->profile->requestEmailChange($user, $input->email);
                }

                $this->profile->updateName($user, $input->name);

                return $this->redirectToRoute('app_account');
            } catch (\RuntimeException) {
                $form->addError(new FormError('profile.change_failed'));
            }
        }

        return $this->renderProfile(
            $user,
            $form,
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    #[Route('/account/email/verify', name: 'app_account_email_verify', methods: ['GET'])]
    public function confirmEmailChange(Request $request): Response
    {
        $user = $this->account();
        $id = $user->getId();
        $form = $this->createForm(ProfileType::class, ProfileInput::fromUser($user));

        try {
            if (null === $id) {
                throw new \RuntimeException('Account update failed.');
            }

            $this->profile->confirmEmailChange($request, $id);

            return $this->redirectToRoute('app_account');
        } catch (\RuntimeException) {
            $form->addError(new FormError('profile.change_failed'));
        }

        return $this->renderProfile($user, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function renderProfile(User $user, FormInterface $form, int $status): Response
    {
        return $this->render('account/profile.html.twig', [
            'account' => $user,
            'form' => $form->createView(),
        ], new Response(status: $status));
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
