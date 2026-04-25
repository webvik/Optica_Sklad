<?php

namespace App\Form;

use App\Entity\SpoolEvent;
use App\Enum\SpoolEventType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class SpoolEventFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'class' => SpoolEventType::class,
                'label' => 'Událost',
                'choices' => [
                    'Odběr dle metru' => SpoolEventType::MeterReading,
                    'Předání' => SpoolEventType::Transfer,
                    'Vyřazení' => SpoolEventType::Writeoff,
                    'Inventura' => SpoolEventType::Inventory,
                    'Oprava' => SpoolEventType::Correction,
                    'Úsek dle štítku' => SpoolEventType::LaidSection,
                ],
                'constraints' => [new NotBlank()],
            ])
            ->add('occurredAt', DateTimeType::class, [
                'label' => 'Kdy (skutečnost / zápis)',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
            ])
            ->add('visibleM', IntegerType::class, [
                'label' => 'Viditelné čtení metru (m, u odběru / inventury, volitelné)',
                'required' => false,
            ])
            ->add('projectLabel', TextType::class, [
                'label' => 'Zakázka / stavba',
                'required' => false,
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Poznámka (komu předáno, důvod vyřazení, … )',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SpoolEvent::class,
            'translation_domain' => false,
        ]);
    }
}
