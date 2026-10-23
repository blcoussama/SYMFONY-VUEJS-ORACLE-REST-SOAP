# NetOps — Spécification (source commune)

Application web de **gestion des incidents et des interventions réseau** d'un opérateur télécom : les agents du centre de supervision (NOC) déclarent les incidents sur les sites du réseau, un superviseur les priorise et les assigne, les techniciens planifient et réalisent les interventions ; des systèmes partenaires consultent et créent des incidents par **API REST** et **SOAP**. Projet personnel destiné à démontrer : **PHP 8.3 / Symfony 7.4 LTS**, Doctrine, Twig, Bootstrap, JavaScript (jQuery et **Vue.js 3**), **SQL sur Oracle** (et PostgreSQL), API **REST et SOAP**, Docker, tests.

## 1. Rôles (Symfony Security, hiérarchie de rôles)
| Rôle | Peut |
|---|---|
| `ROLE_AGENT` | se connecter, déclarer un incident, voir tous les incidents, commenter, voir le tableau Kanban |
| `ROLE_TECHNICIEN` | tout ce que fait l'agent + voir « mes interventions », démarrer / mettre en attente / résoudre les incidents **qui lui sont assignés**, saisir le compte rendu d'intervention |
| `ROLE_SUPERVISEUR` | tout ce qui précède + qualifier (priorité), assigner / réassigner, clore ou rouvrir, tableau de bord |
| `ROLE_ADMIN` | tout + gestion des sites, des utilisateurs (création, rôle, activation), des clés d'API partenaires |

Contrôle par ressource avec des **Voters** (un technicien ne modifie que ses incidents ; 403 sinon). Compte désactivé ⇒ connexion refusée. CSRF sur tous les formulaires. Limitation des tentatives de connexion (`login_throttling`).

## 2. Domaine (Doctrine ORM, portable Oracle / PostgreSQL / SQLite)
- **User** : email (unique), nom, prénom, mot de passe haché (`auto`), rôles, actif, créé le.
- **Site** : code (unique, ex. `TNG-0042`), nom, ville, région, type ∈ `ANTENNE_4G | ANTENNE_5G | CENTRAL | FIBRE_NRO | DATACENTER`, actif.
- **Incident** : référence lisible unique `INC-AAAA-NNNNNN`, titre (≤ 150), description, site, priorité ∈ `P1 | P2 | P3 | P4`, statut (voir §3), catégorie ∈ `PANNE_ENERGIE | COUPURE_FIBRE | EQUIPEMENT | RADIO | LOGICIEL | AUTRE`, déclarant, technicien assigné (nullable), **échéance SLA** (calculée), créé le, résolu le, clos le, version (verrou optimiste).
- **Intervention** : incident, technicien, planifiée le, démarrée le, terminée le, compte rendu (≤ 2000), durée calculée.
- **IncidentEvent** (journal immuable) : incident, type (`CREATION | TRANSITION | ASSIGNATION | COMMENTAIRE | PRIORITE`), de/vers statut, auteur, commentaire, date.
- **ApiClient** : nom du partenaire, clé d'API **hachée** (affichée une seule fois à la création), actif, dernière utilisation.

Noms de tables et colonnes courts et sans mots réservés Oracle (≤ 30 caractères, pas `USER`, `LEVEL`, `COMMENT`, `SIZE`… ⇒ préfixe `nop_`), identifiants par séquence/identité gérés par Doctrine, dates en `datetime_immutable` UTC. Migrations Doctrine **par plateforme** si nécessaire.

## 3. Cycle de vie (composant **Symfony Workflow**, type state_machine)
```
NOUVEAU --qualifier--> QUALIFIE --assigner--> ASSIGNE --demarrer--> EN_COURS --resoudre--> RESOLU --clore--> CLOS
                                                  ^           |  ^                           |
                                                  |   mettre_en_attente / reprendre (EN_ATTENTE)
                                                  '------------- rouvrir (RESOLU -> ASSIGNE) -'
annuler : NOUVEAU | QUALIFIE -> ANNULE (superviseur)
```
Gardes : qualifier/assigner/clore/rouvrir/annuler = superviseur ; démarrer/attente/reprendre/résoudre = technicien assigné ; résoudre exige un compte rendu d'intervention. Chaque transition crée un `IncidentEvent`. Transition interdite ⇒ message clair (HTML) / 409 (API).

**SLA** : délai de résolution par priorité (P1 = 4 h, P2 = 8 h, P3 = 24 h, P4 = 72 h, configurables) ; échéance = création + délai ; incident « en dépassement » si non résolu après l'échéance. Le temps passé `EN_ATTENTE` ne suspend pas le SLA (simplification documentée).

## 4. Interfaces web (Twig + **Bootstrap 5**, en français)
- Connexion. Barre de navigation selon le rôle.
- **Liste des incidents** : filtres statut / priorité / site / technicien / « en dépassement SLA », recherche, tri, pagination ; **filtrage dynamique en AJAX avec jQuery** (le tableau se recharge sans recharger la page), et sélection dépendante région → sites (jQuery).
- **Détail d'un incident** : informations, badge SLA (restant / dépassé), boutons des transitions autorisées (selon le Workflow et le rôle), formulaire d'assignation, interventions, journal chronologique, commentaires.
- **Déclarer un incident** (formulaire Symfony + Validator).
- **Tableau Kanban en Vue.js 3** (`/kanban`) : colonnes par statut, cartes colorées par priorité, glisser-déposer qui appelle l'API REST pour la transition (refus ⇒ la carte revient et un message s'affiche) ; Vue chargé via AssetMapper / importmap (pas de build Node obligatoire).
- **Mes interventions** (technicien) : planifier, démarrer, terminer avec compte rendu.
- **Tableau de bord** (superviseur) : incidents ouverts par priorité, taux de respect du SLA, temps moyen de résolution, top 5 des sites en incident, tendance 30 jours ; requêtes **SQL** via QueryBuilder/DQL + au moins un rapport en **SQL natif** (Doctrine DBAL) documenté.
- **Administration** : sites (CRUD), utilisateurs (création, rôle, activation), clés d'API (création ⇒ clé affichée une fois, révocation).

## 5. API REST (JSON, `/api/v1`, authentification par en-tête `X-API-Key` pour les partenaires, ou session pour le Kanban)
- `GET /incidents` (filtres `status`, `priority`, `site`, pagination `page`/`limit` ≤ 100) · `GET /incidents/{reference}` · `POST /incidents` (création par un partenaire : titre, description, codeSite, catégorie, priorité suggérée) · `POST /incidents/{reference}/transitions/{transition}` (`{ "comment": … }`) · `GET /sites` · `GET /health`.
- Erreurs au format **RFC 7807** (`application/problem+json`), validation ⇒ 422 avec détail par champ, 404, 403, 409 (transition interdite ou conflit de version), 401 (clé absente/invalide). Documentation OpenAPI (fichier `docs/openapi.yaml` ou NelmioApiDocBundle).

## 6. API SOAP (extension `ext-soap`, `/soap/incidents`, WSDL servi sur `/soap/incidents?wsdl`)
Opérations : `getIncidentStatus(reference)` ⇒ statut, priorité, échéance SLA, technicien ; `createIncident(siteCode, title, description, category, priority)` ⇒ référence ; `listOpenIncidentsBySite(siteCode)`. Authentification par clé d'API (en-tête SOAP `ApiKey` ou en-tête HTTP `X-API-Key`), fautes SOAP explicites (`Client.NotFound`, `Client.Unauthorized`, `Client.Validation`). WSDL rédigé à la main, versionné dans `config/soap/incidents.wsdl`, et un client d'exemple en PHP.

## 7. Qualité attendue
PHP 8.3, `declare(strict_types=1)`, architecture Symfony standard (Controller fins, services métier, Repository, Voter, Workflow, Form, DTO), **PHPStan niveau 8**, PHP-CS-Fixer (règles @Symfony), **PHPUnit** : tests unitaires (SLA, références, gardes), tests fonctionnels (`WebTestCase` : connexion, droits par rôle, transitions, formulaires, AJAX), tests d'API REST et SOAP. Fixtures de démonstration (DoctrineFixturesBundle, données **fictives**, mots de passe documentés comme factices). Aucun secret versionné. README honnête (origine : projet personnel), captures réelles.
