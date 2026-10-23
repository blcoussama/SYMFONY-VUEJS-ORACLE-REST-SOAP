<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Création / modification d'un utilisateur par l'administrateur (mot de passe facultatif en modification).
 *
 * @extends AbstractType<User>
 */
final class UserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordConstraints = [
            new Assert\Length(min: 8, max: 72, minMessage: 'Au moins {{ limit }} caractères.'),
            new Assert\Regex(pattern: '/\d/', message: 'Le mot de passe doit contenir au moins un chiffre.'),
        ];
        if ($options['require_password']) {
            $passwordConstraints[] = new Assert\NotBlank(message: 'Le mot de passe est obligatoire.');
        }

        $builder
            ->add('email', EmailType::class, ['label' => 'Adresse e-mail', 'empty_data' => ''])
            ->add('firstName', TextType::class, ['label' => 'Prénom', 'empty_data' => ''])
            ->add('lastName', TextType::class, ['label' => 'Nom', 'empty_data' => ''])
            ->add('role', EnumType::class, [
                'label' => 'Rôle',
                'class' => UserRole::class,
                'choice_label' => static fn (UserRole $r): string => $r->label(),
            ])
            ->add('active', CheckboxType::class, ['label' => 'Compte actif', 'required' => false])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => $options['require_password'],
                'first_options' => ['label' => 'Mot de passe', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmation', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'constraints' => $passwordConstraints,
                'help' => $options['require_password'] ? null : 'Laisser vide pour conserver le mot de passe actuel.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => true,
        ]);
        $resolver->setAllowedTypes('require_password', 'bool');
    }
}
