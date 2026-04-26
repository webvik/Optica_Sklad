<?php

namespace App\Form;

use App\Entity\CableFamily;
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
                'required' => false,
                'placeholder' => '— zatím neuvedeno, doplníte na kartě cívky —',
                'choice_label' => static fn (CableType $c) => $c->getCode().' — '.$c->getName(),
                'label' => 'Typ kabelu',
                'query_builder' => static fn ($r) => $r->createQueryBuilder('c')
                    ->andWhere('c.isActive = true')
                    ->orderBy('c.name', 'ASC'),
                'choice_value' => static function (?CableType $c): string {
                    return null === $c ? '' : (string) $c->getId();
                },
                'choice_attr' => static function (mixed $choice, string $key, string $value): array {
                    if (!$choice instanceof CableType) {
                        return [];
                    }

                    return [
                        'data-family' => $choice->getFamily(),
                    ];
                },
                'attr' => [
                    'class' => 'cable-type-list',
                    'size' => '8',
                ],
            ])
            ->add('cableFamilyFilter', EntityType::class, [
                'class' => CableFamily::class,
                'mapped' => false,
                'required' => false,
                'label' => 'Kabel, typ',
                'placeholder' => '— vyberte typ (řadu) —',
                'choice_label' => static fn (CableFamily $f): string => $f->getLabel(),
                'choice_value' => static function (?CableFamily $f): string {
                    // placeholder / prázdná volba volá s null
                    return null === $f ? '' : $f->getCode();
                },
                'query_builder' => static fn ($r) => $r->createQueryBuilder('f')
                    ->andWhere('f.isActive = true')
                    ->orderBy('f.sortOrder', 'ASC')
                    ->addOrderBy('f.label', 'ASC'),
                'attr' => [
                    'class' => 'spool-cable-family-filter',
                ],
            ])
            ->add('reelNumber', TextType::class, [
                'label' => 'Číslo saře',
                'constraints' => [new NotBlank()],
            ])
            ->add('totalLengthM', IntegerType::class, [
                'label' => 'Délka L (m)',
                'constraints' => [new NotBlank(), new Positive()],
            ])
            ->add('initialVisibleM', IntegerType::class, [
                'label' => 'PS (m)',
                'constraints' => [new NotBlank()],
            ])
            ->add('fiberCount', IntegerType::class, [
                'label' => 'Počet vláken',
                'required' => false,
            ])
            ->add('diameterMm', NumberType::class, [
                'label' => 'Ø (mm)',
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
