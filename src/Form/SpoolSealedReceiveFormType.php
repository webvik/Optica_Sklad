<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CableFamily;
use App\Entity\CableType;
use App\Repository\CableTypeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

/** Příjem nerozbalené cívky (typ + L z dokladu; saře volitelně, PS až při rozbalení). */
final class SpoolSealedReceiveFormType extends AbstractType
{
    public function __construct(
        private readonly CableTypeRepository $cableTypes,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reelNumber', TextType::class, [
                'label' => 'Číslo saře',
                'required' => false,
                'attr' => [
                    'placeholder' => 'pokud je na dokladu — jinak nechte prázdné',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('cableType', EntityType::class, [
                'class' => CableType::class,
                'required' => false,
                'placeholder' => '— typ z dokladu (nebo doplníte později) —',
                'choice_label' => static fn (CableType $c) => $c->getCode().' — '.$c->getName(),
                'label' => 'Typ kabelu',
                'choices' => $this->cableTypes->findAllOrderedForCableTypePicker(true),
                'choice_value' => static function (?CableType $c): string {
                    return null === $c ? '' : (string) $c->getId();
                },
                'choice_attr' => static function (mixed $choice, string $key, string $value): array {
                    if (!$choice instanceof CableType) {
                        return [];
                    }

                    return CableTypeChoiceAttrHelper::forCableType($choice);
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
                'label' => 'Kabel, typ (family)',
                'placeholder' => '— pokud není typ zásoby —',
                'choice_label' => static fn (CableFamily $f): string => $f->getLabel(),
                'choice_value' => static function (?CableFamily $f): string {
                    return null === $f ? '' : $f->getCode();
                },
                'query_builder' => static fn ($r) => $r->createQueryBuilder('f')
                    ->andWhere('f.isActive = true')
                    ->orderBy('f.sortOrder', 'ASC')
                    ->addOrderBy('f.label', 'ASC'),
            ])
            ->add('totalLengthM', IntegerType::class, [
                'label' => 'Délka L (m) dle dokladu',
                'constraints' => [new NotBlank(), new Positive()],
            ])
            ->add('fiberCount', IntegerType::class, [
                'label' => 'Počet vláken (prázdné = z typu)',
                'required' => false,
            ])
            ->add('diameterMm', NumberType::class, [
                'label' => 'Ø (mm) (prázdné = z typu)',
                'scale' => 1,
                'html5' => false,
                'required' => false,
                'attr' => ['placeholder' => 'např. 9,9'],
            ])
            ->add('quantity', IntegerType::class, [
                'mapped' => false,
                'label' => 'Počet cívek (stejný typ a L)',
                'data' => 1,
                'constraints' => [new NotBlank(), new Range(min: 1, max: 50)],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Poznámka (dodací list, místo…)',
                'required' => false,
            ])
            ->add('registeredAt', DateType::class, [
                'label' => 'Datum příjmu',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => \App\Entity\Spool::class,
            'translation_domain' => false,
        ]);
    }
}
