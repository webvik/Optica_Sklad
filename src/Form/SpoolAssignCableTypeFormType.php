<?php

namespace App\Form;

use App\Entity\CableType;
use App\Entity\Spool;
use App\Repository\CableTypeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Doplnění typu kabelu u cívky, u které byl typ při zaevidování vynechán.
 */
final class SpoolAssignCableTypeFormType extends AbstractType
{
    public function __construct(
        private readonly CableTypeRepository $cableTypes,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('cableType', EntityType::class, [
            'class' => CableType::class,
            'label' => 'Typ kabelu',
            'choice_label' => static fn (CableType $c) => $c->getCode().' — '.$c->getName(),
            'choices' => $this->cableTypes->findAllOrderedForCableTypePicker(true),
            'constraints' => [new NotBlank(message: 'Vyberte typ kabelu.')],
            'help' => 'Uložením se typ naváže na tuto cívku (řada se převezme z katalogu).',
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
