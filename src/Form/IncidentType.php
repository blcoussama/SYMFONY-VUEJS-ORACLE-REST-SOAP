<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\IncidentDraft;
use App\Entity\Incident;
use App\Entity\Site;
use App\Enum\IncidentCategory;
use App\Enum\IncidentPriority;
use App\Repository\SiteRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Déclaration d'un incident. La liste des sites est filtrée par région côté navigateur
 * (jQuery + /ajax/sites) ; sans JavaScript, tous les sites actifs restent proposés.
 *
 * @extends AbstractType<IncidentDraft>
 */
final class IncidentType extends AbstractType
{
    public function __construct(private readonly SiteRepository $sites)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $regions = $this->sites->findRegions();

        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'attr' => ['maxlength' => Incident::TITLE_MAX_LENGTH, 'placeholder' => 'Ex. Perte de synchronisation radio sur le secteur 2'],
            ])
            ->add('region', ChoiceType::class, [
                'label' => 'Région',
                'required' => false,
                'placeholder' => 'Toutes les régions',
                'choices' => array_combine($regions, $regions),
                'attr' => ['data-region-select' => 'true', 'data-sites-target' => '#incident_site'],
                'help' => 'Filtre la liste des sites.',
            ])
            ->add('site', EntityType::class, [
                'label' => 'Site concerné',
                'class' => Site::class,
                'query_builder' => static fn (SiteRepository $repository) => $repository->activeQueryBuilder(),
                'choice_label' => 'label',
                'choice_attr' => static fn (Site $site): array => ['data-region' => $site->getRegion()],
                'placeholder' => 'Choisir un site',
            ])
            ->add('category', EnumType::class, [
                'label' => 'Catégorie',
                'class' => IncidentCategory::class,
                'choice_label' => static fn (IncidentCategory $c): string => $c->label(),
                'placeholder' => 'Choisir une catégorie',
            ])
            ->add('priority', EnumType::class, [
                'label' => 'Priorité proposée',
                'class' => IncidentPriority::class,
                'choice_label' => static fn (IncidentPriority $p): string => $p->label(),
                'help' => 'Le superviseur la confirme lors de la qualification.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'attr' => ['rows' => 6, 'maxlength' => 4000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IncidentDraft::class,
        ]);
    }
}
