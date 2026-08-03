<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Event;

enum PasswordChangeOrigin
{
    case Authenticated;
    case Reset;
}
