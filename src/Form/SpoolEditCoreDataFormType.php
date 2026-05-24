<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Spool;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

/** Úprava základních údajů cívky na kartě (L, PS, vlákna, Ø). */
final class SpoolEditCoreDataFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('totalLengthM', IntegerType::class, [
                'label' => 'Délka L (m)',
                'constraints' => [new NotBlank(), new Positive()],
            ])
            ->add('initialVisibleM', IntegerType::class, [
                'label' => 'PS (m) — počáteční stav na kabelu',
                'constraints' => [new NotBlank()],
            ])
            ->add('fiberCount', IntegerType::class, [
                'label' => 'Počet vláken (prázdné = z typu kabelu, pokud je)',
                'required' => false,
            ])
            ->add('diameterMm', NumberType::class, [
                'label' => 'Ø (mm) (prázdné = z typu kabelu, pokud je)',
                'scale' => 1,
                'html5' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => 'např. 9,9 nebo 9.9',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Spool::class,
            'translation_domain' => false,
            'csrf_protection' => false,
        ]);
    }
}
