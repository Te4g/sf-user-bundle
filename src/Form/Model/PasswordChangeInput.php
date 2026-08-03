<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

final class PasswordChangeInput
{
    #[Assert\NotBlank]
    public string $currentPassword = '';

    #[Assert\NotBlank]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
    public string $plainPassword = '';
}
