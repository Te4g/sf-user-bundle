<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Util;

final class EmailNormalizer
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
