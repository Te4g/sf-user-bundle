<?php

declare(strict_types=1);

namespace Acme\Bundle\TemplateBundle\Service;

final readonly class ExampleService
{
    public function __construct(
        private string $message,
    ) {
    }

    public function message(): string
    {
        return $this->message;
    }
}
