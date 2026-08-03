<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType as SymfonyEmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Te4g\UserBundle\Form\Model\EmailInput;

final class EmailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', SymfonyEmailType::class, [
            'label' => 'form.email',
            'attr' => ['autocomplete' => 'email'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailInput::class,
            'translation_domain' => 'Te4gUserBundle',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'email';
    }
}
