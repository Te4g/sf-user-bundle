# Customization

Installed templates, components, assets, icons, translations, entity, repositories, and migration belong to the application. The examples below use bundle contracts so application policy stays outside vendor code.

## Extend registration

Add application-only fields with a Symfony form type extension. An unmapped terms checkbox does not require changing the bundle input DTO:

```php
<?php

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Te4g\UserBundle\Form\RegistrationType;

final class RegistrationTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('agreeTerms', CheckboxType::class, [
            'mapped' => false,
            'constraints' => [new IsTrue(message: 'You must accept the terms.')],
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [RegistrationType::class];
    }
}
```

With the default Symfony service autoconfiguration, no manual tag is needed.

## Replace a template

The installer already writes application-owned overrides below `templates/bundles/Te4gUserBundle/`; edit those files directly. Alternatively, point one configuration key at a new application template:

```yaml
te4g_user:
    templates:
        login: account/login.html.twig
```

```twig
{# templates/account/login.html.twig #}
{% extends 'bundles/Te4gUserBundle/security/login.html.twig' %}

{% block title %}Sign in to Example{% endblock %}
{% block body %}
    <p class="mx-auto max-w-md pt-8">Use your Example account.</p>
    {{ parent() }}
{% endblock %}
```

If you copy a generated template, keep its CSRF fields, neutral response copy, and POST-only security actions intact.

## Supply a repository adapter

Configure any service implementing `AccountRepositoryInterface`:

```php
<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;

final readonly class DoctrineAccountRepository implements AccountRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findOneByEmail(string $email): ?AccountUserInterface
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function findById(int|string $id): ?AccountUserInterface
    {
        return $this->entityManager->find(User::class, $id);
    }

    public function save(AccountUserInterface $user): void
    {
        $this->assertSupported($user);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function remove(AccountUserInterface $user): void
    {
        $this->assertSupported($user);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    private function assertSupported(AccountUserInterface $user): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Expected %s.', User::class));
        }
    }
}
```

```yaml
te4g_user:
    user_class: App\Entity\User
    account_repository: App\Security\DoctrineAccountRepository
```

## React to lifecycle events

Available events include `AccountRegisteredEvent`, `AccountVerifiedEvent`, `EmailChangeRequestedEvent`, `EmailChangedEvent`, `PasswordChangedEvent`, `BeforeAccountDeletedEvent`, and `AfterAccountDeletedEvent`.

```php
<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Te4g\UserBundle\Event\AccountRegisteredEvent;

final readonly class AccountRegisteredSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public static function getSubscribedEvents(): array
    {
        return [AccountRegisteredEvent::class => 'onRegistered'];
    }

    public function onRegistered(AccountRegisteredEvent $event): void
    {
        $this->logger->info('account_registered', [
            'account_id' => (string) $event->getUser()->getId(),
        ]);
    }
}
```

Event objects may expose email addresses. Do not serialize or indiscriminately log whole event/user objects.

## Guard account deletion

Deletion guards are autoconfigured and evaluated before removal:

```php
<?php

namespace App\Security;

use Te4g\UserBundle\Contract\AccountDeletionGuardInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Exception\AccountDeletionRejected;

final class OwnerDeletionGuard implements AccountDeletionGuardInterface
{
    public function assertDeletionAllowed(AccountUserInterface $user): void
    {
        if (in_array('ROLE_OWNER', $user->getRoles(), true)) {
            throw new AccountDeletionRejected('owner_account_requires_transfer');
        }
    }
}
```

Keep account deletion enabled for the guard to run. Set `features.account_deletion: false` only when the application intentionally removes self-service deletion entirely.
