<?php

namespace App\Form;

use App\Entity\CableType;
use App\Entity\Spool;
use App\Enum\SpoolStatus;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

final class SpoolFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('cableType', EntityType::class, [
                'class' => CableType::class,
                'choice_label' => static fn (CableType $c) => $c->getCode().' — '.$c->getName(),
                'label' => 'Typ kabelu',
                'query_builder' => static fn ($r) => $r->createQueryBuilder('c')
                    ->andWhere('c.isActive = true')
                    ->orderBy('c.name', 'ASC'),
                'constraints' => [new NotBlank()],
            ])
            ->add('reelNumber', TextType::class, [
                'label' => 'Číslo bubnu / cívky (jedinečné)',
                'constraints' => [new NotBlank()],
            ])
            ->add('totalLengthM', IntegerType::class, [
                'label' => 'Délka L (m, celé číslo)',
                'constraints' => [new NotBlank(), new Positive()],
            ])
            ->add('initialVisibleM', IntegerType::class, [
                'label' => 'm₀ na viditelném konci (m)',
                'help' => 'První zachycené čtení po rozbalení',
                'constraints' => [new NotBlank()],
            ])
            ->add('fiberCount', IntegerType::class, [
                'label' => 'Počet vláken (pokud se liší od typu)',
                'required' => false,
            ])
            ->add('diameterMm', NumberType::class, [
                'label' => 'Ø (mm, fakt, jedno des. místo)',
                'scale' => 1,
                'required' => false,
            ])
            ->add('status', EnumType::class, [
                'class' => SpoolStatus::class,
                'label' => 'Stav',
                'choice_label' => static function (SpoolStatus $s): string {
                    return match ($s) {
                        SpoolStatus::InStock => 'na skladě',
                        SpoolStatus::Transferred => 'předáno',
                        SpoolStatus::WrittenOff => 'vyřazeno',
                    };
                },
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Poznámka',
                'required' => false,
            ])
            ->add('registeredAt', DateType::class, [
                'label' => 'Datum zaevidování',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Spool::class,
            'translation_domain' => false,
        ]);
    }
}
