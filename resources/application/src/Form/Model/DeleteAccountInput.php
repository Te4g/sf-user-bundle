<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

final class DeleteAccountInput
{
    #[Assert\NotBlank]
    public string $currentPassword = '';

    #[Assert\IsTrue(message: 'account.delete_confirmation_required')]
    public bool $confirm = false;
}
