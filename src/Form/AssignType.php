<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class AssignType extends AbstractType
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('technician', EntityType::class, [
                'label' => 'Technicien',
                'class' => User::class,
                'choices' => $this->users->findActiveTechnicians(),
                'choice_label' => 'fullName',
                'placeholder' => 'Choisir un technicien',
                'constraints' => [new Assert\NotNull(message: 'Choisissez un technicien.')],
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
        $resolver->setDefaults(['csrf_token_id' => 'incident_assign']);
    }
}
