<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

final class PasswordChangeInput
{
    #[Assert\NotBlank]
    public string $currentPassword = '';

    #[Assert\Length(min: 8, minMessage: 'password.too_short')]
    public string $plainPassword = '';
}
