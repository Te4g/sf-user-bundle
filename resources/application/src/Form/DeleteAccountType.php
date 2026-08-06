<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\DeleteAccountInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DeleteAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'form.current_password',
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'form.delete_confirmation',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeleteAccountInput::class,
            'csrf_token_id' => 'account_delete',
        ]);
    }
}
