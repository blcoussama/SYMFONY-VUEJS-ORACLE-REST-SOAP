<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ApiClientFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nom du partenaire',
            'attr' => ['placeholder' => 'Ex. Supervision OSS région Nord', 'maxlength' => 100],
            'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
        ]);
    }
}
