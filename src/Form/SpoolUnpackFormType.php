<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Spool;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/** Doplnění saře + PS u nerozbalené cívky → přechod na operativní sklad. */
final class SpoolUnpackFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reelNumber', TextType::class, [
                'label' => 'Číslo saře',
                'constraints' => [new NotBlank(message: 'Zadejte číslo saře.')],
            ])
            ->add('initialVisibleM', IntegerType::class, [
                'label' => 'PS (m) — počáteční stav na kabelu',
                // NotNull: PS může být 0; NotBlank u int někdy matoucí.
                'constraints' => [new NotNull(message: 'Zadejte PS (počáteční stav metru).')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Spool::class,
            'translation_domain' => false,
            // CSRF je vlastní token v šabloně (spool_unpack_{id}), ne formový.
            'csrf_protection' => false,
        ]);
    }
}
