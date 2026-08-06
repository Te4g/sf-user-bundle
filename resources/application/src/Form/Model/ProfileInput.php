<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

final class ProfileInput
{
    #[Assert\Length(max: 120)]
    #[Assert\NotBlank]
    public string $name = '';

    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[Assert\NotBlank]
    public string $email = '';

    public static function fromUser(User $user): self
    {
        $input = new self();
        $input->name = $user->getName();
        $input->email = $user->getEmail();

        return $input;
    }
}
