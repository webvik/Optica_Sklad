<?php

namespace App\Form;

use App\Entity\Spool;
use App\Entity\SpoolEvent;
use App\Enum\SpoolEventType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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
                    'Zafuk' => SpoolEventType::MeterReading,
                    'Vyřazení' => SpoolEventType::Writeoff,
                    'Předání' => SpoolEventType::Transfer,
                    'Inventura' => SpoolEventType::Inventory,
                    'Korekce' => SpoolEventType::Correction,
                ],
                'constraints' => [new NotBlank()],
            ])
            ->add('occurredAt', DateType::class, [
                'label' => 'Datum (skutečnost / zápis)',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
                'html5' => true,
            ])
            ->add('visibleM', IntegerType::class, [
                'label' => 'Viditelné čtení metru (m, u zafuku / inventury, volitelné)',
                'required' => false,
            ])
            ->add('writeoffRemainderM', IntegerType::class, [
                'label' => 'Zbytek ke zrušení (m)',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => 'js-spool-writeoff-remainder', 'min' => 1],
                'help' => 'Fyzický kabel, který končí (motek, odpad); obvykle = zůstatek v evidenci. V poznámce lze upřesnit.',
                'help_attr' => ['class' => 'spool-form__help--writeoff-zbytek'],
            ])
            ->add('projectLabel', TextType::class, [
                'label' => 'Zakázka / stavba',
                'required' => false,
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Poznámka (komu předáno, důvod vyřazení, … )',
                'required' => false,
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) use ($options): void {
            if (!$options['spool'] instanceof Spool) {
                return;
            }
            $form = $e->getForm();
            if (!$form->has('writeoffRemainderM')) {
                return;
            }
            $spool = $options['spool'];
            $book = $spool->getCurrentRemainingM();
            if (null === $book) {
                $book = $spool->getTotalLengthM();
            }
            if ($book >= 1) {
                $form->get('writeoffRemainderM')->setData($book);
            }
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $e) use ($options): void {
            $data = $e->getData();
            if (!$data instanceof SpoolEvent) {
                return;
            }
            if (SpoolEventType::Writeoff !== $data->getType()) {
                return;
            }
            if (!$options['spool'] instanceof Spool) {
                $e->getForm()->addError(new FormError('Chyba: chybí kontext cívky.'));

                return;
            }
            $spool = $options['spool'];
            $book = $spool->getCurrentRemainingM();
            if (null === $book) {
                $book = $spool->getTotalLengthM();
            }
            if ($book < 1) {
                $e->getForm()->addError(new FormError('V evidenci není kabel ke zrušení (zůstatek 0 m).'));

                return;
            }
            $f = $e->getForm();
            if (!$f->has('writeoffRemainderM')) {
                return;
            }
            $raw = $f->get('writeoffRemainderM')->getData();
            if (null === $raw || '' === $raw) {
                $f->get('writeoffRemainderM')->addError(new FormError('U vyřazení zadejte zbytek ke zrušení (m).'));

                return;
            }
            $r = (int) $raw;
            if ($r < 1) {
                $f->get('writeoffRemainderM')->addError(new FormError('Zbytek musí být alespoň 1 m.'));

                return;
            }
            if ($r > $book) {
                $f->get('writeoffRemainderM')->addError(
                    new FormError('Zbytek ('.$r.' m) nemůže být větší než zůstatek v evidenci ('.$book.' m).')
                );
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SpoolEvent::class,
            'translation_domain' => false,
            'spool' => null,
        ]);
        $resolver->setAllowedTypes('spool', ['null', Spool::class]);
    }
}
