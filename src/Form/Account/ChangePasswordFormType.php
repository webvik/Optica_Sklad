<?php

declare(strict_types=1);

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Současné heslo',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank(message: 'Zadejte současné heslo.'),
                    new UserPassword(message: 'Současné heslo není správné.'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => true,
                'invalid_message' => 'Nová hesla musejí být stejná.',
                'first_options' => [
                    'label' => 'Nové heslo',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Nové heslo znovu',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new NotBlank(message: 'Zadejte nové heslo.'),
                    new Length(
                        min: 8,
                        max: 4096,
                        minMessage: 'Heslo alespoň {{ limit }} znaků.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
