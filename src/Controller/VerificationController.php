<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Exception\VerificationFailed;
use Te4g\UserBundle\Form\EmailType;
use Te4g\UserBundle\Form\Model\EmailInput;
use Te4g\UserBundle\Manager\EmailVerificationManager;
use Te4g\UserBundle\Util\EmailNormalizer;

final class VerificationController extends AbstractController
{
    public function __construct(
        private readonly EmailVerificationManager $verificationManager,
        private readonly AccountRepositoryInterface $repository,
        private readonly AccountRequestLimiter $requestLimiter,
        private readonly UserBundleOptions $options,
        private readonly EmailNormalizer $emailNormalizer = new EmailNormalizer(),
    ) {
    }

    public function verify(Request $request): Response
    {
        $id = $request->query->get('id');

        if (!is_scalar($id)) {
            return $this->redirectToRoute('te4g_user_login');
        }

        try {
            $this->verificationManager->verify($request->getUri(), (string) $id);
        } catch (VerificationFailed) {
            return $this->redirectToRoute('te4g_user_login');
        }

        return $this->redirectToRoute($this->options->redirects['verified']);
    }

    public function resend(Request $request): Response
    {
        $input = new EmailInput();
        $form = $this->createForm(EmailType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render($this->options->templates['check_email'], [
                'resend_form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if ($this->requestLimiter->accept($request, $input->email)) {
            $user = $this->repository->findOneByEmail($this->emailNormalizer->normalize($input->email));

            if (null !== $user && !$user->isVerified()) {
                $this->verificationManager->send($user);
            }
        }

        return $this->redirectToRoute('te4g_user_check_email');
    }
}
