<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Te4g\UserBundle\Form\Model\ProfileInput;

final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'form.new_email',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('currentPassword', PasswordType::class, [
                'label' => 'form.current_password',
                'attr' => ['autocomplete' => 'current-password'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileInput::class,
            'translation_domain' => 'Te4gUserBundle',
        ]);
    }
}
