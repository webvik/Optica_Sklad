<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SpoolEvent;
use App\Enum\SpoolEventType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class SpoolEventEditFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $allowVisibleM = (bool) $options['allow_visible_m'];
        $eventType = $options['event_type'];
        $showProject = $eventType instanceof SpoolEventType
            && (SpoolEventType::MeterReading === $eventType
                || SpoolEventType::LaidSection === $eventType
                || SpoolEventType::Inventory === $eventType
                || SpoolEventType::Correction === $eventType);

        $builder
            ->add('occurredAt', DateType::class, [
                'label' => 'Datum (skutečnost / zápis)',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
                'html5' => true,
                'constraints' => [new NotBlank()],
            ]);

        if ($allowVisibleM) {
            $builder->add('visibleM', IntegerType::class, [
                'label' => 'Viditelné čtení metru (m)',
                'constraints' => [new NotBlank(message: 'Zadejte čtení metru (m).')],
            ]);
        }

        if ($showProject) {
            $builder->add('projectLabel', TextType::class, [
                'label' => 'Zakázka / stavba',
                'required' => false,
            ]);
        }

        $builder->add('note', TextareaType::class, [
            'label' => SpoolEventType::Transfer === $eventType
                ? 'Poznámka (komu předáno)'
                : 'Poznámka',
            'required' => SpoolEventType::Transfer === $eventType,
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $e) use ($eventType): void {
            if (SpoolEventType::Transfer !== $eventType) {
                return;
            }
            $data = $e->getData();
            if (!$data instanceof SpoolEvent) {
                return;
            }
            if ('' === \trim((string) ($data->getNote() ?? ''))) {
                $e->getForm()->get('note')->addError(new FormError('U předání vyplňte poznámku (komu předáno).'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SpoolEvent::class,
            'translation_domain' => false,
            'allow_visible_m' => false,
            'event_type' => null,
        ]);
        $resolver->setAllowedTypes('allow_visible_m', 'bool');
        $resolver->setAllowedTypes('event_type', ['null', SpoolEventType::class]);
    }
}
