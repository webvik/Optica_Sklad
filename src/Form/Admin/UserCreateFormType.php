<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Security\WarehouseRole;
use App\Util\WhatsAppPhoneDigits;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class UserCreateFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Jméno',
                'required' => false,
                'constraints' => [new Length(max: 100)],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Příjmení',
                'required' => false,
                'constraints' => [new Length(max: 100)],
            ])
            ->add('phone', TextType::class, [
                'mapped' => false,
                'label' => 'Telefon (volitelně)',
                'required' => false,
                'help' => 'Pro jednorázové odeslání loginu a hesla přes WhatsApp (např. +420 7xx xxx xxx).',
                'constraints' => [new Length(max: 32)],
            ])
            ->add('username', TextType::class, [
                'label' => 'Přihlašovací jméno (login)',
                'help' => 'Bez diakritiky, výhodně jako jméno.prijmení (malá písmena).',
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 2, max: 180),
                    new Regex(pattern: '/^[a-z0-9._-]+$/i', message: 'Použijte jen písmena bez háčků, čísla, tečku a podtržení.'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => true,
                'first_options' => ['label' => 'Heslo'],
                'second_options' => ['label' => 'Heslo znovu'],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 8, max: 4096, minMessage: 'Heslo alespoň {{ limit }} znaků.'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => false,
                'constraints' => [
                    new Email(),
                    new Length(max: 180),
                ],
            ])
            ->add('offerWhatsAppHandoff', CheckboxType::class, [
                'mapped' => false,
                'label' => 'Po uložení nabídnout odeslání loginu a hesla uživateli přes WhatsApp',
                'required' => false,
                'data' => true,
                'help' => 'Vyžaduje vyplněné telefonní číslo. Odkaz funguje jednou; heslo se dočasně uloží jen do souboru v serveru a po otevření WhatsApp se smaže.',
            ])
            ->add('accessLevel', ChoiceType::class, [
                'mapped' => false,
                'label' => 'Úroveň oprávnění',
                'choices' => WarehouseRole::formChoicesOrdered(),
                'expanded' => true,
                'data' => WarehouseRole::EDIT,
                'attr' => ['class' => 'spool-form__expanded-choice'],
            ])
            ->add('save', SubmitType::class, ['label' => 'Založit účet']);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            if (!$form->isValid()) {
                return;
            }

            $offer = true === $form->get('offerWhatsAppHandoff')->getData();
            $phoneRaw = trim((string) $form->get('phone')->getData());
            if (!$offer) {
                return;
            }

            if ('' === $phoneRaw) {
                $form->get('phone')->addError(new FormError('Pro odeslání přes WhatsApp vyplňte telefonní číslo.'));

                return;
            }

            if (null === WhatsAppPhoneDigits::normalize($phoneRaw)) {
                $form->get('phone')->addError(new FormError('Zadejte platné číslo pro WhatsApp (např. +420 7xx xxx xxx).'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
