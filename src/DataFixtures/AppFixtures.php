<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ApiClient;
use App\Entity\Incident;
use App\Entity\IncidentEvent;
use App\Entity\Intervention;
use App\Entity\ReferenceCounter;
use App\Entity\Site;
use App\Entity\User;
use App\Enum\IncidentCategory;
use App\Enum\IncidentEventType;
use App\Enum\IncidentPriority;
use App\Enum\IncidentSource;
use App\Enum\IncidentStatus;
use App\Enum\SiteType;
use App\Enum\UserRole;
use App\Security\ApiKeyHasher;
use App\Service\Reference\IncidentReference;
use App\Service\Sla\SlaPolicy;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Données de démonstration ENTIÈREMENT FICTIVES : sites, personnes, codes et incidents inventés.
 *
 * Comptes (mot de passe factice commun « Demo1234! », à ne jamais réutiliser ailleurs) :
 *   admin@netops.example, superviseur@netops.example, technicien1@netops.example,
 *   technicien2@netops.example, agent1@netops.example, agent2@netops.example
 * Clés d'API factices : DEMO_API_KEY (active), REVOKED_API_KEY (révoquée).
 *
 * L'historique est écrit directement (sans passer par le Workflow) pour pouvoir être daté
 * dans le passé ; il reste cohérent avec la machine à états. Génération déterministe (graine fixe).
 */
final class AppFixtures extends Fixture
{
    public const DEMO_PASSWORD = 'Demo1234!';
    public const DEMO_API_KEY = 'nop_demo_cle_factice_pour_tests_locaux_0001';
    public const REVOKED_API_KEY = 'nop_demo_cle_factice_revoquee_0002';

    public const ADMIN = 'admin@netops.example';
    public const SUPERVISOR = 'superviseur@netops.example';
    public const TECHNICIAN_1 = 'technicien1@netops.example';
    public const TECHNICIAN_2 = 'technicien2@netops.example';
    public const AGENT_1 = 'agent1@netops.example';
    public const AGENT_2 = 'agent2@netops.example';

    /** Code de site de démonstration toujours présent et actif. */
    public const SITE_TANGER = 'TNG-0042';
    /** Site désactivé (aucun incident ne peut y être déclaré). */
    public const SITE_INACTIVE = 'LAA-0009';

    private const SITES = [
        ['TNG-0042', 'Tanger Ville — Boulevard Pasteur', 'Tanger', 'Tanger-Tétouan-Al Hoceïma', SiteType::Antenne5G],
        ['TNG-0107', 'Tanger Med — Zone portuaire', 'Tanger', 'Tanger-Tétouan-Al Hoceïma', SiteType::FibreNro],
        ['TNG-0001', 'Central Tanger Iberia', 'Tanger', 'Tanger-Tétouan-Al Hoceïma', SiteType::Central],
        ['TET-0015', 'Tétouan — Martil', 'Tétouan', 'Tanger-Tétouan-Al Hoceïma', SiteType::Antenne4G],
        ['AHC-0008', 'Al Hoceïma — Quemado', 'Al Hoceïma', 'Tanger-Tétouan-Al Hoceïma', SiteType::Antenne4G],
        ['OUJ-0021', 'Oujda Centre', 'Oujda', 'L’Oriental', SiteType::Antenne4G],
        ['NAD-0006', 'Nador — Corniche', 'Nador', 'L’Oriental', SiteType::Antenne5G],
        ['FES-0030', 'Central Fès Saïss', 'Fès', 'Fès-Meknès', SiteType::Central],
        ['MEK-0012', 'Meknès — Hamria', 'Meknès', 'Fès-Meknès', SiteType::Antenne4G],
        ['RBT-0003', 'Datacenter Rabat Technopolis', 'Rabat', 'Rabat-Salé-Kénitra', SiteType::Datacenter],
        ['RBT-0058', 'Rabat — Agdal', 'Rabat', 'Rabat-Salé-Kénitra', SiteType::Antenne5G],
        ['KEN-0019', 'Kénitra — Maamora', 'Kénitra', 'Rabat-Salé-Kénitra', SiteType::FibreNro],
        ['CAS-0001', 'Datacenter Casablanca Nearshore', 'Casablanca', 'Casablanca-Settat', SiteType::Datacenter],
        ['CAS-0144', 'Casablanca — Maârif', 'Casablanca', 'Casablanca-Settat', SiteType::Antenne5G],
        ['CAS-0231', 'Casablanca — Sidi Maârouf', 'Casablanca', 'Casablanca-Settat', SiteType::FibreNro],
        ['ELJ-0005', 'El Jadida Centre', 'El Jadida', 'Casablanca-Settat', SiteType::Antenne4G],
        ['MRK-0027', 'Marrakech — Guéliz', 'Marrakech', 'Marrakech-Safi', SiteType::Antenne5G],
        ['SAF-0004', 'Central Safi Port', 'Safi', 'Marrakech-Safi', SiteType::Central],
        ['AGA-0016', 'Agadir — Founty', 'Agadir', 'Souss-Massa', SiteType::Antenne4G],
        ['ERR-0002', 'Errachidia Centre', 'Errachidia', 'Drâa-Tafilalet', SiteType::Antenne4G],
        ['LAA-0009', 'Laâyoune Centre (démonté)', 'Laâyoune', 'Laâyoune-Sakia El Hamra', SiteType::FibreNro],
    ];

    private const USERS = [
        [self::ADMIN, 'Nadia', 'Bennani', UserRole::Admin],
        [self::SUPERVISOR, 'Youssef', 'El Amrani', UserRole::Superviseur],
        [self::TECHNICIAN_1, 'Karim', 'Tazi', UserRole::Technicien],
        [self::TECHNICIAN_2, 'Imane', 'Chraibi', UserRole::Technicien],
        [self::AGENT_1, 'Omar', 'Idrissi', UserRole::Agent],
        [self::AGENT_2, 'Salma', 'Berrada', UserRole::Agent],
    ];

    /** @var array<string, list<string>> titres plausibles par catégorie */
    private const TITLES = [
        'PANNE_ENERGIE' => ['Coupure secteur, site sur batteries', 'Groupe électrogène en défaut', 'Redresseur en alarme majeure', 'Climatisation à l’arrêt, température élevée'],
        'COUPURE_FIBRE' => ['Coupure fibre suite à travaux de voirie', 'Perte de lien optique vers le NRO', 'Atténuation anormale sur la boucle optique', 'Câble arraché sur le tronçon aérien'],
        'EQUIPEMENT' => ['Carte de transmission hors service', 'Routeur d’agrégation redémarré en boucle', 'Alimentation redondante défaillante', 'Ventilation du baie en panne'],
        'RADIO' => ['Secteur 2 hors service', 'Perte de synchronisation radio', 'Taux d’échec d’appels anormal', 'Interférences signalées sur la bande 1800'],
        'LOGICIEL' => ['Licence expirée sur le contrôleur', 'Mise à jour logicielle à reprendre', 'Alarmes intempestives après correctif', 'Fuite mémoire sur le superviseur local'],
        'AUTRE' => ['Intrusion signalée sur le site', 'Accès site impossible (clé perdue)', 'Dégât des eaux dans le local technique', 'Balisage du pylône éteint'],
    ];

    private const REPORTS = [
        'Remplacement de la carte défectueuse, tests de bout en bout concluants.',
        'Soudure de la fibre effectuée, niveau optique revenu à la normale.',
        'Redémarrage contrôlé et mise à jour du logiciel, alarmes levées.',
        'Remise en service de l’alimentation, batteries rechargées.',
        'Réglage de l’antenne et vérification des indicateurs radio.',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SlaPolicy $slaPolicy,
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        mt_srand(2026);
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $now = $now->setTime((int) $now->format('H'), (int) $now->format('i'));

        $sites = [];
        foreach (self::SITES as [$code, $name, $city, $region, $type]) {
            $site = new Site($code, $name, $city, $region, $type);
            $site->setActive(self::SITE_INACTIVE !== $code);
            $manager->persist($site);
            $sites[] = $site;
        }
        $activeSites = array_values(array_filter($sites, static fn (Site $s): bool => $s->isActive()));

        /** @var array<string, User> $users */
        $users = [];
        foreach (self::USERS as [$email, $first, $last, $role]) {
            $user = new User($email, $first, $last, $role, $now->modify('-90 days'));
            $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
            $manager->persist($user);
            $users[$email] = $user;
        }

        $partner = new ApiClient('OSS Supervision Nord (démo)', ApiKeyHasher::displayPrefix(self::DEMO_API_KEY), ApiKeyHasher::hash(self::DEMO_API_KEY), $now->modify('-60 days'));
        $partner->markUsed($now->modify('-2 hours'));
        $manager->persist($partner);
        $revoked = new ApiClient('Ancien portail partenaire (démo)', ApiKeyHasher::displayPrefix(self::REVOKED_API_KEY), ApiKeyHasher::hash(self::REVOKED_API_KEY), $now->modify('-200 days'));
        $revoked->revoke($now->modify('-30 days'));
        $manager->persist($revoked);

        $supervisor = $users[self::SUPERVISOR];
        $technicians = [$users[self::TECHNICIAN_1], $users[self::TECHNICIAN_2]];
        $agents = [$users[self::AGENT_1], $users[self::AGENT_2]];

        // Statut visé et âge (heures) de chaque incident : les ouverts sont récents, les terminés étalés sur 30 jours.
        $plan = [];
        foreach ([[IncidentStatus::Nouveau, 6, 0.3, 10], [IncidentStatus::Qualifie, 5, 1, 20], [IncidentStatus::Assigne, 6, 2, 30],
            [IncidentStatus::EnCours, 6, 2, 40], [IncidentStatus::EnAttente, 3, 6, 60], [IncidentStatus::Resolu, 6, 8, 120],
            [IncidentStatus::Clos, 6, 48, 700], [IncidentStatus::Annule, 2, 24, 400]] as [$status, $count, $minAge, $maxAge]) {
            for ($i = 0; $i < $count; ++$i) {
                $plan[] = ['status' => $status, 'age' => $minAge + ($maxAge - $minAge) * mt_rand(0, 1000) / 1000];
            }
        }
        usort($plan, static fn (array $a, array $b): int => $b['age'] <=> $a['age']);

        $counters = [];
        $techIndex = 0;
        foreach ($plan as $n => $item) {
            $status = $item['status'];
            $createdAt = $now->modify(\sprintf('-%d minutes', (int) round($item['age'] * 60)));
            $priority = [IncidentPriority::P1, IncidentPriority::P2, IncidentPriority::P2, IncidentPriority::P3, IncidentPriority::P3, IncidentPriority::P3, IncidentPriority::P4][mt_rand(0, 6)];
            $category = IncidentCategory::cases()[mt_rand(0, \count(IncidentCategory::cases()) - 1)];
            $site = 0 === $n % 7 ? $activeSites[0] : $activeSites[mt_rand(0, \count($activeSites) - 1)];
            $titles = self::TITLES[$category->value];
            $fromPartner = 0 === $n % 6;
            $reporter = $agents[$n % 2];

            $year = (int) $createdAt->format('Y');
            $counters[$year] = ($counters[$year] ?? 0) + 1;
            $incident = new Incident(
                IncidentReference::format($year, $counters[$year]),
                $titles[mt_rand(0, \count($titles) - 1)],
                \sprintf("Alarme remontée par la supervision sur %s.\nImpact estimé : %s.", $site->getLabel(), ['clients mobiles du secteur', 'liaisons d’entreprise', 'trafic de collecte', 'aucun impact client constaté'][mt_rand(0, 3)]),
                $site,
                $category,
                $priority,
                $fromPartner ? IncidentSource::Rest : IncidentSource::Web,
                $createdAt,
                $this->slaPolicy->dueAt($priority, $createdAt),
            );
            $incident->declaredBy($fromPartner ? null : $reporter, $fromPartner ? $partner : null);
            $manager->persist($incident);
            $reporterLabel = $fromPartner ? $partner->getName().' (API REST)' : $reporter->getFullName();
            $this->event($manager, $incident, IncidentEventType::Creation, $reporterLabel, $createdAt, $fromPartner ? null : $reporter, null, IncidentStatus::Nouveau, \sprintf('Incident déclaré (%s), priorité %s.', $incident->getSource()->label(), $priority->value));

            $this->replayHistory($manager, $incident, $status, $createdAt, $now, $supervisor, $technicians[$techIndex % 2], $n);
            if (\in_array($status, [IncidentStatus::Assigne, IncidentStatus::EnCours, IncidentStatus::EnAttente, IncidentStatus::Resolu, IncidentStatus::Clos], true)) {
                ++$techIndex;
            }
        }

        foreach ($counters as $year => $value) {
            $manager->persist(new ReferenceCounter(IncidentReference::counterKey($year), $value));
        }

        $manager->flush();
    }

    /**
     * Rejoue un historique daté et cohérent jusqu'au statut visé.
     */
    private function replayHistory(ObjectManager $manager, Incident $incident, IncidentStatus $target, \DateTimeImmutable $createdAt, \DateTimeImmutable $now, User $supervisor, User $technician, int $n): void
    {
        $order = [IncidentStatus::Nouveau, IncidentStatus::Qualifie, IncidentStatus::Assigne, IncidentStatus::EnCours, IncidentStatus::Resolu, IncidentStatus::Clos];
        $span = max(10, (int) (($now->getTimestamp() - $createdAt->getTimestamp()) / 60));
        if (\in_array($target, [IncidentStatus::Resolu, IncidentStatus::Clos], true)) {
            // Incident terminé : traité dans le délai SLA, sauf environ un sur quatre.
            $slaMinutes = 60 * $this->slaPolicy->hoursFor($incident->getPriority());
            $workSpan = 0 === $n % 4 ? (int) ($slaMinutes * 1.4) : (int) ($slaMinutes * (0.35 + 0.4 * mt_rand(0, 100) / 100));
            $span = max(10, min($workSpan, $span - 10));
        }
        $at = static fn (float $ratio): \DateTimeImmutable => $createdAt->modify(\sprintf('+%d minutes', max(1, (int) ($span * $ratio))));
        $supervisorLabel = $supervisor->getFullName();
        $techLabel = $technician->getFullName();

        if (IncidentStatus::Annule === $target) {
            $from = 0 === $n % 2 ? IncidentStatus::Nouveau : IncidentStatus::Qualifie;
            if (IncidentStatus::Qualifie === $from) {
                $this->transition($manager, $incident, IncidentStatus::Nouveau, IncidentStatus::Qualifie, $supervisor, $at(0.1));
            }
            $this->transition($manager, $incident, $from, IncidentStatus::Annule, $supervisor, $at(0.3), 'Fausse alarme : le site a été rétabli automatiquement.');
            $incident->setStatus($target);

            return;
        }

        $targetRank = IncidentStatus::EnAttente === $target ? 3 : (int) array_search($target, $order, true);

        if ($targetRank >= 1) {
            $this->transition($manager, $incident, IncidentStatus::Nouveau, IncidentStatus::Qualifie, $supervisor, $at(0.05));
        }
        if ($targetRank >= 2) {
            $incident->assignTo($technician, $at(0.1));
            $this->event($manager, $incident, IncidentEventType::Assignation, $supervisorLabel, $at(0.1), $supervisor, null, null, \sprintf('Assigné à %s.', $techLabel));
            $this->transition($manager, $incident, IncidentStatus::Qualifie, IncidentStatus::Assigne, $supervisor, $at(0.1));
            if (2 === $targetRank && 0 === $n % 2) {
                // Intervention planifiée dans les prochaines heures.
                $manager->persist(new Intervention($incident, $technician, $now->modify(\sprintf('+%d hours', 1 + $n % 5)), $at(0.2)));
            }
        }
        if ($targetRank >= 3) {
            $startedAt = $at(0.25);
            $intervention = new Intervention($incident, $technician, $startedAt, $at(0.15));
            $intervention->start($startedAt);
            $manager->persist($intervention);
            $this->transition($manager, $incident, IncidentStatus::Assigne, IncidentStatus::EnCours, $technician, $startedAt, 'Intervention démarrée.');

            if (IncidentStatus::EnAttente === $target) {
                $this->transition($manager, $incident, IncidentStatus::EnCours, IncidentStatus::EnAttente, $technician, $at(0.5), 'En attente de pièce de rechange (livraison prévue).');
            }
            if ($targetRank >= 4) {
                $resolvedAt = $at(0.9);
                $intervention->finish($resolvedAt, self::REPORTS[$n % \count(self::REPORTS)]);
                $this->event($manager, $incident, IncidentEventType::Commentaire, $techLabel, $resolvedAt, $technician, null, null, 'Intervention terminée.');
                $this->transition($manager, $incident, IncidentStatus::EnCours, IncidentStatus::Resolu, $technician, $resolvedAt);
                $incident->markResolved($resolvedAt);

                if (IncidentStatus::Clos === $target) {
                    $closedAt = min($resolvedAt->modify('+3 hours'), $now->modify('-1 minute'));
                    $this->transition($manager, $incident, IncidentStatus::Resolu, IncidentStatus::Clos, $supervisor, $closedAt, 'Retour à la normale confirmé par la supervision.');
                    $incident->markClosed($closedAt);
                }
            }
        }

        if (0 === $n % 5 && $incident->isOpen()) {
            $this->event($manager, $incident, IncidentEventType::Commentaire, $supervisorLabel, $at(0.12), $supervisor, null, null, 'Client entreprise impacté : merci de tenir le NOC informé.');
        }
        $incident->setStatus($target);
    }

    private function transition(ObjectManager $manager, Incident $incident, IncidentStatus $from, IncidentStatus $to, User $author, \DateTimeImmutable $at, ?string $comment = null): void
    {
        $this->event($manager, $incident, IncidentEventType::Transition, $author->getFullName(), $at, $author, $from, $to, $comment);
        $incident->touch($at);
    }

    private function event(ObjectManager $manager, Incident $incident, IncidentEventType $type, string $authorLabel, \DateTimeImmutable $at, ?User $author, ?IncidentStatus $from, ?IncidentStatus $to, ?string $comment): void
    {
        $manager->persist(new IncidentEvent($incident, $type, $authorLabel, $at, $author, $from, $to, $comment));
    }
}
