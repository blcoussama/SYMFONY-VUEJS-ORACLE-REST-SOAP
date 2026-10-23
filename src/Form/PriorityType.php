<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\IncidentPriority;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Qualification (statut NOUVEAU) ou changement de priorité d'un incident ouvert.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class PriorityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('priority', EnumType::class, [
                'label' => 'Priorité',
                'class' => IncidentPriority::class,
                'choice_label' => static fn (IncidentPriority $p): string => $p->label(),
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Commentaire (facultatif)',
                'required' => false,
                'attr' => ['rows' => 2, 'maxlength' => 1000],
                'constraints' => [new Assert\Length(max: 1000)],
            ])
            ->add('version', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'incident_priority']);
    }
}
