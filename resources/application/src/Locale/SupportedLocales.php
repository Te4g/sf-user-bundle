<?php

declare(strict_types=1);

namespace App\Locale;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final readonly class SupportedLocales
{
    /** @param array<string, string> $labels */
    public function __construct(
        #[Autowire(param: 'app.supported_locales')]
        private array $labels,
        #[Autowire(param: 'kernel.default_locale')]
        private string $defaultLocale,
    ) {
        if (!isset($labels[$defaultLocale])) {
            throw new \InvalidArgumentException('The default locale must be supported.');
        }
    }

    public function supports(string $locale): bool
    {
        return isset($this->labels[$locale]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->labels);
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return $this->labels;
    }

    public function default(): string
    {
        return $this->defaultLocale;
    }

    public function resolve(Request $request, ?User $user): string
    {
        if (null !== $user && $this->supports($user->getLocale())) {
            return $user->getLocale();
        }

        $cookie = $request->cookies->getString('app_locale');
        if ($this->supports($cookie)) {
            return $cookie;
        }

        $preferred = $request->getPreferredLanguage($this->codes());

        return null !== $preferred && $this->supports($preferred) ? $preferred : $this->defaultLocale;
    }
}
