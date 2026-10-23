<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class InterventionPlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plannedAt', DateTimeType::class, [
            'label' => 'Date et heure prévues',
            'widget' => 'single_text',
            // Saisie au format français (le sélecteur natif suivrait la langue du système).
            'html5' => false,
            'format' => 'dd/MM/yyyy HH:mm',
            'attr' => ['placeholder' => 'jj/mm/aaaa hh:mm', 'inputmode' => 'numeric', 'autocomplete' => 'off'],
            'invalid_message' => 'Date attendue au format jj/mm/aaaa hh:mm.',
            'input' => 'datetime_immutable',
            'model_timezone' => 'UTC',
            'view_timezone' => 'Africa/Casablanca',
            'constraints' => [new Assert\NotNull(message: 'Indiquez la date prévue.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'intervention_plan']);
    }
}
