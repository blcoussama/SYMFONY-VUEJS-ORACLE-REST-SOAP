<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Site;
use App\Enum\SiteType;
use App\Service\MoroccoRegions;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Site>
 */
final class SiteFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code',
                'attr' => ['placeholder' => 'TNG-0042', 'maxlength' => 8, 'autocapitalize' => 'characters'],
                'help' => '3 lettres (ville), un tiret, 4 chiffres.',
                'empty_data' => '',
            ])
            ->add('name', TextType::class, ['label' => 'Nom', 'empty_data' => ''])
            ->add('city', TextType::class, ['label' => 'Ville', 'empty_data' => ''])
            ->add('region', ChoiceType::class, [
                'label' => 'Région',
                'choices' => MoroccoRegions::choices(),
                'placeholder' => 'Choisir une région',
                'empty_data' => '',
            ])
            ->add('type', EnumType::class, [
                'label' => 'Type de site',
                'class' => SiteType::class,
                'choice_label' => static fn (SiteType $t): string => $t->label(),
            ])
            ->add('active', CheckboxType::class, ['label' => 'Site actif', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Site::class]);
    }
}
