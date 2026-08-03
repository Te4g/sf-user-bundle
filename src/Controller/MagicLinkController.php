<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Form\EmailType;
use Te4g\UserBundle\Form\Model\EmailInput;
use Te4g\UserBundle\Manager\MagicLinkManager;

final class MagicLinkController extends AbstractController
{
    public function __construct(
        private readonly MagicLinkManager $magicLinkManager,
        private readonly AccountRequestLimiter $requestLimiter,
        private readonly UserBundleOptions $options,
    ) {
    }

    public function request(Request $request): Response
    {
        $input = new EmailInput();
        $form = $this->createForm(EmailType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render($this->options->templates['login'], [
                'last_username' => '',
                'authentication_failed' => false,
                'magic_link_form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if ($this->requestLimiter->accept($request, $input->email)) {
            $this->magicLinkManager->request($input->email);
        }

        return $this->redirectToRoute('te4g_user_check_email');
    }

    public function check(Request $request): Response
    {
        if ($request->isMethod(Request::METHOD_POST)) {
            return $this->redirectToRoute('te4g_user_login');
        }

        $parameters = [];
        foreach (['expires', 'user', 'hash'] as $name) {
            $value = $request->query->get($name);
            if (is_scalar($value)) {
                $parameters[$name] = (string) $value;
            }
        }

        return $this->render($this->options->templates['login_link_check'], [
            'login_link_parameters' => $parameters,
        ]);
    }
}
