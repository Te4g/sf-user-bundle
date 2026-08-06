<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationInput
{
    #[Assert\Length(max: 120)]
    #[Assert\NotBlank]
    public string $name = '';

    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[Assert\NotBlank]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
    public string $plainPassword = '';
}
