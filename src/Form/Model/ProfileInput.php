<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;
use Te4g\UserBundle\Contract\AccountUserInterface;

final class ProfileInput
{
    #[Assert\Email]
    #[Assert\NotBlank]
    public string $email = '';

    #[Assert\NotBlank]
    public string $currentPassword = '';

    public static function fromAccount(AccountUserInterface $account): self
    {
        $input = new self();
        $input->email = $account->getEmail();

        return $input;
    }
}
