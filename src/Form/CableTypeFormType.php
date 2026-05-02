<?php

namespace App\Form;

use App\Entity\CableType;
use App\Repository\CableFamilyRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
    public function __construct(
        private readonly CableFamilyRepository $cableFamilyRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $familyChoices = $this->buildFamilyChoices($options['data'] ?? null);

        $builder
            ->add('code', TextType::class, [
                'label' => 'Kód zásoby',
                'constraints' => [new NotBlank()],
            ])
            ->add('fullDescription', TextareaType::class, [
                'label' => 'Plný popis',
                'required' => false,
            ])
            ->add('family', ChoiceType::class, [
                'label' => 'Řada (blown, mlt, …)',
                'choices' => $familyChoices,
                'placeholder' => '— vyberte —',
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
                /** false → čárku i tečku zpracuje NumberFormatter jako u cívky (Ø mm) */
                'html5' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => 'např. 9,9 nebo 9.9',
                ],
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

    /**
     * @return array<string, string> label => uložený kód (cable_type.family)
     */
    private function buildFamilyChoices(mixed $cableType): array
    {
        $choices = [];
        foreach ($this->cableFamilyRepository->findForPicker() as $f) {
            $choices[$f->getLabel()] = $f->getCode();
        }

        $current = $cableType instanceof CableType ? $cableType->getFamily() : '';
        if ('' === $current) {
            return $choices;
        }

        $values = array_values($choices);
        if (\in_array($current, $values, true)) {
            return $choices;
        }

        $unknown = $this->cableFamilyRepository->findOneBy(['code' => $current]);
        if (null !== $unknown) {
            $label = $unknown->getLabel();
            if (!$unknown->isActive()) {
                $label .= ' (vypnuto v katalogu)';
            }

            return [$label => $current] + $choices;
        }

        return [sprintf('%s (není v katalogu řad)', $current) => $current] + $choices;
    }
}
