<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

final class EmailInput
{
    #[Assert\Email]
    #[Assert\NotBlank]
    public string $email = '';
}
