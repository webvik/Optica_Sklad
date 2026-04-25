<?php

namespace App\Form;

use App\Entity\CableType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

final class CableTypeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Kód (KО-04-9-… )',
                'constraints' => [new NotBlank()],
            ])
            ->add('name', TextType::class, [
                'label' => 'Stručný název',
                'constraints' => [new NotBlank()],
            ])
            ->add('fullDescription', TextareaType::class, [
                'label' => 'Plný popis (jako v 1C/PDF)',
                'required' => false,
            ])
            ->add('family', TextType::class, [
                'label' => 'Řada (blown, mlt, …)',
                'constraints' => [new NotBlank()],
            ])
            ->add('fiberCount', IntegerType::class, [
                'label' => 'Počet vláken',
                'constraints' => [new NotBlank(), new Positive()],
            ])
            ->add('constructionCode', TextType::class, [
                'label' => 'Kód konstrukce (Z444…)',
                'required' => false,
            ])
            ->add('diameterMm', NumberType::class, [
                'label' => 'Průměr (mm)',
                'scale' => 1,
                'required' => false,
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Aktivní v katalogu',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CableType::class,
            'translation_domain' => false,
        ]);
    }
}
