<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Form\DeleteAccountType;
use Te4g\UserBundle\Form\Model\DeleteAccountInput;

final class AccountController extends AbstractController
{
    public function __construct(private readonly UserBundleOptions $options)
    {
    }

    public function index(): Response
    {
        $account = $this->account();
        $deletionForm = null;

        if ($this->options->deletionEnabled) {
            $deletionForm = $this->createForm(DeleteAccountType::class, new DeleteAccountInput(), [
                'action' => $this->generateUrl('te4g_user_account_delete'),
            ]);
        }

        return $this->render($this->options->templates['account'], [
            'account' => $account,
            'deletion_form' => $deletionForm,
            'deletion_failed' => false,
        ]);
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
