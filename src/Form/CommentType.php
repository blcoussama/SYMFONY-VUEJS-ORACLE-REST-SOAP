<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\IncidentEvent;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class CommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('text', TextareaType::class, [
            'label' => 'Ajouter un commentaire',
            'attr' => ['rows' => 3, 'maxlength' => IncidentEvent::COMMENT_MAX_LENGTH],
            'constraints' => [
                new Assert\NotBlank(message: 'Le commentaire est vide.'),
                new Assert\Length(max: IncidentEvent::COMMENT_MAX_LENGTH),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'incident_comment']);
    }
}
