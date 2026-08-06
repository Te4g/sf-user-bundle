<?php

declare(strict_types=1);

namespace App\Account;

final class EmailNormalizer
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
