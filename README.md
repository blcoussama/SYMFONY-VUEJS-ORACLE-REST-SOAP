# NetOps — gestion des incidents réseau d'un opérateur télécom

[![CI](https://github.com/blcoussama/SYMFONY-VUEJS-ORACLE-REST-SOAP/actions/workflows/ci.yml/badge.svg)](https://github.com/blcoussama/SYMFONY-VUEJS-ORACLE-REST-SOAP/actions/workflows/ci.yml)
[![Licence MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

**Projet personnel** de démonstration (toutes les données sont **fictives**) : une application web où les
agents du centre de supervision (NOC) déclarent les incidents survenus sur les sites du réseau, un
superviseur les priorise et les assigne, et les techniciens planifient puis réalisent les interventions.
Des systèmes partenaires consultent et créent des incidents par **API REST** et **SOAP**.

Objectif technique : PHP 8.3 / **Symfony 7.4 LTS**, Doctrine, Twig + Bootstrap 5, jQuery et **Vue.js 3**,
**SQL sur Oracle** (et PostgreSQL, SQLite), REST et SOAP, Docker, tests automatisés et CI.

> La spécification d'origine est dans [`docs/SPEC.md`](docs/SPEC.md), le contrat REST dans
> [`docs/openapi.yaml`](docs/openapi.yaml) et le contrat SOAP dans
> [`config/soap/incidents.wsdl`](config/soap/incidents.wsdl).

## Sommaire

- [Captures](#captures)
- [Fonctionnalités par rôle](#fonctionnalités-par-rôle)
- [Stack](#stack)
- [Architecture](#architecture)
- [Cycle de vie et SLA](#cycle-de-vie-et-sla)
- [API REST](#api-rest)
- [API SOAP](#api-soap)
- [Démarrage avec Docker (Oracle)](#démarrage-avec-docker-oracle)
- [Démarrage sans Docker (SQLite)](#démarrage-sans-docker-sqlite)
- [Variables d'environnement](#variables-denvironnement)
- [Comptes de démonstration](#comptes-de-démonstration)
- [Oracle : ce qui a été fait](#oracle--ce-qui-a-été-fait)
- [Tests et CI](#tests-et-ci)
- [Sécurité](#sécurité)
- [Limites connues](#limites-connues)
- [État d'avancement vérifié](#état-davancement-vérifié)
- [Licence](#licence)

## Captures

Captures réelles de l'application (données de démonstration fictives), prises avec Playwright sur la
pile e2e (`e2e/`, application en mode prod servie par le serveur PHP intégré, base SQLite).

| Connexion | Liste filtrée (AJAX jQuery) | Détail et journal |
|---|---|---|
| ![Connexion](docs/screenshots/01-connexion.png) | ![Liste des incidents](docs/screenshots/02-liste-filtree.png) | ![Détail d'un incident](docs/screenshots/03-detail-journal.png) |

| Kanban (Vue.js 3) | Tableau de bord (DQL + SQL natif) | Mes interventions |
|---|---|---|
| ![Kanban](docs/screenshots/04-kanban.png) | ![Tableau de bord](docs/screenshots/05-tableau-de-bord.png) | ![Mes interventions](docs/screenshots/06-mes-interventions.png) |

## Fonctionnalités par rôle

Les rôles sont hiérarchiques (`role_hierarchy`) : chaque rôle hérite des droits du précédent.

| Rôle | Ce qu'il peut faire |
|---|---|
| `ROLE_AGENT` | se connecter, déclarer un incident, consulter et filtrer tous les incidents, commenter, voir le Kanban |
| `ROLE_TECHNICIEN` | + « Mes interventions » : planifier, démarrer, terminer avec compte rendu ; démarrer / mettre en attente / reprendre / résoudre **uniquement les incidents qui lui sont assignés** |
| `ROLE_SUPERVISEUR` | + qualifier (priorité), assigner / réassigner, clore, rouvrir, annuler ; tableau de bord (taux de respect du SLA, temps moyen de résolution, top 5 des sites, tendance 30 jours) |
| `ROLE_ADMIN` | + administration des sites (CRUD), des utilisateurs (création, rôle, activation) et des clés d'API partenaires (clé affichée une seule fois, révocation) |
| Partenaire (clé d'API) | REST : lister / consulter / créer des incidents, lister les sites, annuler un incident qu'il a lui-même déclaré ; SOAP : statut, création, incidents ouverts d'un site |

Côté interface : liste des incidents avec filtres (statut, priorité, site, technicien, dépassement SLA),
recherche, tri et pagination rechargés **en AJAX avec jQuery** ; sélection dépendante région → sites ;
Kanban **Vue.js 3** dont le glisser-déposer appelle l'API REST (refus ⇒ la carte revient et un message
s'affiche) ; badges SLA (restant / dépassé) ; journal chronologique immuable de chaque incident.

## Stack

| Domaine | Choix |
|---|---|
| Langage, framework | PHP 8.3 (`declare(strict_types=1)` partout), Symfony 7.4 LTS |
| Persistance | Doctrine ORM 3.7, DBAL 4.5, Migrations 3.9 (une migration par plateforme) |
| Bases | **Oracle XE 21** (pilote `oci8`, Instant Client 23.26), PostgreSQL 17, SQLite |
| Interface | Twig, Bootstrap 5.3, jQuery 4, Vue.js 3.5 via AssetMapper / importmap (aucun build Node) |
| API | REST JSON (erreurs RFC 7807, OpenAPI 3.1), SOAP 1.1 document/literal (`ext-soap`, WSDL écrit à la main) |
| Qualité | PHPUnit 12 + DAMADoctrineTestBundle, PHPStan niveau 8, PHP-CS-Fixer (@Symfony, @Symfony:risky), Playwright (e2e) |
| Exploitation | Docker multi-étapes (php-fpm Debian + nginx non root), Docker Compose, GitHub Actions |

## Architecture

```
  navigateur : Twig + Bootstrap, jQuery (AJAX), Vue 3 (Kanban)     partenaires : REST (X-API-Key), SOAP
                         │                                                   │
                         ▼                                                   ▼
   ┌───────────────────────── nginx 1.30 (non root, port 8080) ─────────────────────────┐
   │  /assets/* : fichiers compilés par AssetMapper (cache long)   tout le reste → FastCGI │
   └──────────────────────────────────────────┬──────────────────────────────────────────┘
                                              ▼
   ┌───────────────────────── php-fpm 8.3 — Symfony 7.4 (non root) ─────────────────────┐
   │  Contrôleurs fins (web, /api/v1, /soap) → services métier (IncidentManager,          │
   │  InterventionManager, SlaPolicy, IncidentReferenceGenerator, IncidentJournal)         │
   │  Security : pare-feux « main » (formulaire, CSRF, login_throttling),                   │
   │             « api_key » (sans état, X-API-Key), « soap » (clé vérifiée par le service) │
   │             Voters : IncidentVoter, InterventionVoter (403 si la ressource n'est pas   │
   │             la sienne)                                                                  │
   │  Workflow « incident » (state_machine) : gardes (IncidentGuardListener) et journal      │
   │             IncidentEvent (IncidentWorkflowSubscriber)                                  │
   │  Doctrine ORM / DBAL : DQL + QueryBuilder, rapports en SQL natif (NativeSqlReports)     │
   └──────────────┬──────────────────────────────────────────────────┬─────────────────────┘
                  ▼ oci8 + Oracle Instant Client 23.26                ▼ pdo_pgsql / pdo_sqlite
        Oracle XE 21 (PDB XEPDB1, schéma NETOPS)              PostgreSQL 17 · SQLite (dev, tests)
```

- **Workflow** : la machine à états est déclarée dans [`config/packages/workflow.yaml`](config/packages/workflow.yaml) ;
  les gardes métier (rôle, technicien assigné, compte rendu obligatoire) sont dans
  `App\Workflow\IncidentGuardListener` ; chaque transition écrit un `IncidentEvent` et met à jour les horodatages.
- **Voters** : `IncidentVoter` (`INCIDENT_VIEW`, `INCIDENT_COMMENT`, `INCIDENT_ASSIGN`, `INCIDENT_PRIORITIZE`,
  `INCIDENT_WORK`, `INCIDENT_PARTNER_CANCEL`) et `InterventionVoter` (`INTERVENTION_EDIT`).
- **Concurrence** : verrou optimiste (`#[ORM\Version]`, en-têtes `ETag` / `If-Match` côté REST, 409 si la version est périmée).

## Cycle de vie et SLA

```
NOUVEAU --qualifier--> QUALIFIE --assigner--> ASSIGNE --demarrer--> EN_COURS --resoudre--> RESOLU --clore--> CLOS
                                                 ^          |  ^                            |
                                                 |   mettre_en_attente / reprendre (EN_ATTENTE)
                                                 '------------- rouvrir (RESOLU -> ASSIGNE) -'
annuler : NOUVEAU | QUALIFIE -> ANNULE
```

| Transition | Qui |
|---|---|
| qualifier, assigner, clore, rouvrir, annuler | superviseur (un partenaire peut aussi annuler un incident qu'il a déclaré) |
| demarrer, mettre_en_attente, reprendre, resoudre | technicien **assigné** ; `resoudre` exige un compte rendu d'intervention |

Une transition interdite donne un message clair dans l'interface et une réponse **409** (`application/problem+json`) dans l'API.

**SLA** : délai de résolution par priorité — P1 = 4 h, P2 = 8 h, P3 = 24 h, P4 = 72 h (configurables par
`SLA_HOURS_P1..P4`). Échéance = création + délai ; un incident non résolu après l'échéance est « en
dépassement ». Simplification assumée : le temps passé `EN_ATTENTE` ne suspend pas le SLA. Un changement de
priorité recalcule l'échéance depuis la création ; une réouverture conserve l'échéance d'origine.

## API REST

Base `/api/v1`, JSON, erreurs au format **RFC 7807** (`application/problem+json`). Authentification par
en-tête `X-API-Key` (partenaires, pare-feu sans état) ou par session + en-tête `X-CSRF-Token` (Kanban).
Contrat complet : [`docs/openapi.yaml`](docs/openapi.yaml).

| Méthode et chemin | Rôle |
|---|---|
| `GET /health` | sonde publique (503 si la base ne répond pas) |
| `GET /sites` | sites actifs |
| `GET /incidents` | filtres `status`, `priority`, `site`, pagination `page` / `limit` (≤ 100) |
| `GET /incidents/{reference}` | détail (`ETag` = version) |
| `POST /incidents` | création par un partenaire (titre, description, codeSite, catégorie, priorité suggérée) |
| `POST /incidents/{reference}/transitions/{transition}` | transition du Workflow (`{"comment": …}`, `If-Match` facultatif) |

Exemples (pile Docker de la section suivante, clé de démonstration **factice** chargée par les fixtures) ;
les réponses ci-dessous ont été obtenues sur la pile Oracle :

```bash
KEY=nop_demo_cle_factice_pour_tests_locaux_0001
API=http://127.0.0.1:8088/api/v1

curl -s $API/health
# {"status":"ok","database":"ok","time":"2026-10-23T16:32:27Z"}

curl -s -H "X-API-Key: $KEY" "$API/incidents?priority=P1&limit=2"

curl -s -i -X POST -H "X-API-Key: $KEY" -H 'Content-Type: application/json' \
  -d '{"title":"Coupure fibre sur le lien de collecte","description":"Alarme LOS remontée par la supervision partenaire.","siteCode":"TNG-0042","category":"COUPURE_FIBRE","priority":"P2"}' \
  $API/incidents
# HTTP/1.1 201 Created · Location: /api/v1/incidents/INC-2026-000041 · ETag: "1"
# {"reference":"INC-2026-000041","status":"NOUVEAU","priority":"P2","sla":{"dueAt":"2026-10-24T00:42:53Z","state":"ON_TRACK","breached":false,"label":"Reste 8 h"},"allowedTransitions":["annuler"],…}

curl -s -X POST -H "X-API-Key: $KEY" -H 'Content-Type: application/json' -H 'If-Match: "1"' \
  -d '{"comment":"Doublon d’un incident existant."}' $API/incidents/INC-2026-000041/transitions/annuler
# 200 : {"reference":"INC-2026-000041","status":"ANNULE","version":2,…}
# Même appel une seconde fois : 409
# {"type":"https://netops.example/problems/transition-refused","title":"Conflit","status":409,"detail":"Cette action n’est pas possible dans le statut actuel de l’incident.",…}
```

Autres réponses d'erreur : 401 (clé absente, invalide ou révoquée), 403 (droit refusé), 404 (incident ou
transition inconnus), 422 (validation, avec le détail par champ).

## API SOAP

`ext-soap`, SOAP 1.1 document/literal, WSDL **écrit à la main** et versionné dans
[`config/soap/incidents.wsdl`](config/soap/incidents.wsdl), servi sur `GET /soap/incidents?wsdl` (l'adresse
réelle du service y est injectée). Authentification par en-tête SOAP `<ApiKey>` ou en-tête HTTP `X-API-Key`.

| Opération | Retour |
|---|---|
| `getIncidentStatus(reference)` | statut, priorité, site, échéance SLA, dépassement, technicien |
| `createIncident(siteCode, title, description, category, priority)` | référence `INC-AAAA-NNNNNN` |
| `listOpenIncidentsBySite(siteCode)` | incidents ouverts du site |

Fautes explicites : `Client.Unauthorized`, `Client.NotFound`, `Client.Validation`, `Server`.

Extrait du WSDL :

```xml
<xsd:element name="createIncident">
    <xsd:complexType>
        <xsd:sequence>
            <xsd:element name="siteCode" type="xsd:string"/>
            <xsd:element name="title" type="xsd:string"/>
            <xsd:element name="description" type="xsd:string"/>
            <xsd:element name="category" type="tns:Category"/>
            <xsd:element name="priority" type="tns:Priority"/>
        </xsd:sequence>
    </xsd:complexType>
</xsd:element>
...
<wsdl:operation name="getIncidentStatus">
    <soap:operation soapAction="urn:netops:incidents:v1#getIncidentStatus"/>
```

Appel brut avec curl (réponse réelle obtenue sur la pile Oracle) :

```bash
curl -s -X POST http://127.0.0.1:8088/soap/incidents \
  -H 'Content-Type: text/xml; charset=utf-8' \
  -H 'SOAPAction: "urn:netops:incidents:v1#getIncidentStatus"' \
  -H "X-API-Key: $KEY" \
  -d '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:inc="urn:netops:incidents:v1">
        <soapenv:Body><inc:getIncidentStatus><inc:reference>INC-2026-000015</inc:reference></inc:getIncidentStatus></soapenv:Body>
      </soapenv:Envelope>'
# <ns1:getIncidentStatusResponse><ns1:incident><ns1:reference>INC-2026-000015</ns1:reference>
#   <ns1:title>Perte de lien optique vers le NRO</ns1:title><ns1:status>EN_ATTENTE</ns1:status>
#   <ns1:priority>P2</ns1:priority><ns1:siteCode>TNG-0042</ns1:siteCode>
#   <ns1:slaDueAt>2026-10-22T13:09:00Z</ns1:slaDueAt><ns1:slaBreached>true</ns1:slaBreached>
#   <ns1:technician>Karim Tazi</ns1:technician></ns1:incident></ns1:getIncidentStatusResponse>
# Avec une clé invalide : HTTP 500 + <faultcode>ns1:Client.Unauthorized</faultcode>
```

Client PHP d'exemple ([`bin/soap-client-demo.php`](bin/soap-client-demo.php), `\SoapClient`) :

```bash
php bin/soap-client-demo.php 'http://127.0.0.1:8088/soap/incidents?wsdl' nop_demo_cle_factice_pour_tests_locaux_0001
```

## Démarrage avec Docker (Oracle)

Prérequis : Docker avec Compose v2, environ 4 Go d'espace disque (image Oracle XE ≈ 2,6 Go) et 2 Go de
mémoire libres pour Oracle. Images PHP construites pour `linux/amd64` (Instant Client utilisé).

```bash
# 1. Oracle XE 21 + php-fpm + nginx ; migrations appliquées au démarrage du conteneur « app »
docker compose up -d --build --wait

# 2. Données de démonstration (PURGE puis recharge le schéma applicatif ; image « test »)
docker compose --profile demo run --rm fixtures

# 3. Application : http://127.0.0.1:8088 (comptes ci-dessous)

# Suite PHPUnit complète sur Oracle, dans un schéma dédié (netops_test)
docker compose --profile test run --rm test

# SQL*Plus dans le conteneur Oracle (ou SQL Developer sur 127.0.0.1:15210, service XEPDB1)
docker compose exec oracle sqlplus netops/change_me_app@//localhost:1521/XEPDB1

docker compose down        # ajouter -v pour supprimer aussi le volume de la base
```

Les valeurs par défaut de `compose.yaml` sont **factices** (poste de développement). Pour les changer, copier
`compose.env.example` en `compose.env` (non versionné), le remplir, puis passer `--env-file compose.env` à
chaque commande `docker compose`.

Ce que contient la pile :

| Service | Image | Rôle |
|---|---|---|
| `oracle` | `gvenzl/oracle-xe:21-slim` | Oracle XE 21, PDB `XEPDB1`, schéma applicatif `netops` et schéma de test `netops_test` créés au premier démarrage, `healthcheck.sh` |
| `app` | cible `prod` du [`Dockerfile`](Dockerfile) | php-fpm 8.3 (Debian trixie), oci8 3.3 + Instant Client 23.26 (téléchargé chez Oracle, SHA-256 vérifiée), pdo_pgsql, pdo_sqlite, soap, intl, opcache, zip ; utilisateur `www-data` ; `HEALTHCHECK` FastCGI (`/ping`) |
| `nginx` | cible `nginx` | nginx non root, assets compilés, port publié sur `127.0.0.1` uniquement |
| `fixtures`, `test` | cible `test` | dépendances de dev (DoctrineFixturesBundle, PHPUnit, PHPStan) ; profils `demo` et `test` |

L'image de production fige au build le cache Symfony, y compris les métadonnées Doctrine dont la stratégie
d'identifiant dépend de la base (SEQUENCE sur Oracle, IDENTITY ailleurs) : elle est donc préchauffée pour
Oracle. Pour une image destinée à PostgreSQL :
`docker build --target prod --build-arg CACHE_WARMUP_DATABASE_URL='postgresql://localhost/netops?serverVersion=17' .`

## Démarrage sans Docker (SQLite)

Prérequis : PHP 8.3 avec `intl`, `mbstring`, `pdo_sqlite` (`soap` pour l'API SOAP, `pdo_pgsql` ou `oci8`
pour les autres bases), Composer 2.

```bash
composer install
# Aucun fichier .env n'est versionné : Symfony Runtime lit alors uniquement l'environnement,
# et chaque variable a une valeur par défaut dans config/services.yaml (SQLite dans var/).
export APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'

php bin/console importmap:install                # Bootstrap, jQuery, Vue dans assets/vendor/
php bin/console doctrine:migrations:migrate -n   # var/data_dev.db
php bin/console doctrine:fixtures:load -n        # données de démonstration
# variables_order=EGPCS : le serveur intégré transmet l'environnement à Symfony Runtime
php -d variables_order=EGPCS -S 127.0.0.1:8000 -t public e2e/router.php

vendor/bin/phpunit                               # 294 tests, base SQLite var/data_test.db
```

Pour PostgreSQL ou Oracle hors Docker, il suffit de fournir `DATABASE_URL` (formats ci-dessous).

## Variables d'environnement

Application (lues par Symfony) :

| Variable | Défaut | Rôle |
|---|---|---|
| `APP_ENV`, `APP_DEBUG` | `dev` | `prod` dans l'image |
| `APP_SECRET` | factice en `dev` / `test` uniquement | **obligatoire en prod** (CSRF, limitation des connexions) |
| `DATABASE_URL` | `sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db` | Oracle : `oci8://user:pass@host:1521?servicename=XEPDB1&charset=AL32UTF8` · PostgreSQL : `postgresql://user:pass@host:5432/netops?serverVersion=17&charset=utf8` |
| `DEFAULT_URI` | `http://127.0.0.1:8000` | URL utilisée hors requête HTTP |
| `SLA_HOURS_P1` … `SLA_HOURS_P4` | `4`, `8`, `24`, `72` | délais SLA en heures |
| `SOAP_WSDL_CACHE` | `0` | option `cache_wsdl` de `\SoapServer` |
| `APP_RUNTIME_OPTIONS` | `{"disable_dotenv":true}` dans l'image | désactive la lecture de `.env` |

Image Docker : `RUN_MIGRATIONS` (`1` = migrations au démarrage de php-fpm), `LOAD_FIXTURES` (`1` = fixtures
au démarrage, cibles dev/test uniquement), `DB_WAIT_TIMEOUT` (attente de la base, en secondes).

Compose (préfixe `NETOPS_`, pour ne jamais se mélanger aux variables de Symfony) : `NETOPS_ORACLE_SYS_PASSWORD`,
`NETOPS_ORACLE_USER`, `NETOPS_ORACLE_PASSWORD`, `NETOPS_ORACLE_TEST_USER`, `NETOPS_ORACLE_TEST_PASSWORD`,
`NETOPS_ORACLE_SERVICE`, `NETOPS_APP_SECRET`, `NETOPS_RUN_MIGRATIONS`, `NETOPS_DB_WAIT_TIMEOUT`,
`NETOPS_HTTP_PORT` (8088), `NETOPS_ORACLE_PORT` (15210). Modèle commenté : `compose.env.example`.

## Comptes de démonstration

Créés par les fixtures. **Données et mot de passe fictifs**, à n'utiliser qu'en démonstration locale.
Mot de passe commun : `Demo1234!`

| Compte | Rôle |
|---|---|
| `admin@netops.example` | ROLE_ADMIN |
| `superviseur@netops.example` | ROLE_SUPERVISEUR |
| `technicien1@netops.example`, `technicien2@netops.example` | ROLE_TECHNICIEN |
| `agent1@netops.example`, `agent2@netops.example` | ROLE_AGENT |

Clés d'API factices : `nop_demo_cle_factice_pour_tests_locaux_0001` (active) et
`nop_demo_cle_factice_revoquee_0002` (révoquée ⇒ 401). Une vraie clé se crée dans *Administration → Clés d'API*
ou avec `php bin/console app:api-client:create "Nom du partenaire"` ; seule son empreinte est stockée.

Jeu de données : 21 sites (codes fictifs `TNG-0042`…), 40 incidents répartis sur tous les statuts,
190 événements de journal, interventions ; dates relatives à « maintenant ».

## Oracle : ce qui a été fait

- **Connexion** : extension `oci8` 3.3 compilée contre Oracle Instant Client 23.26 (basic + sdk) dans l'image ;
  `DATABASE_URL` en `oci8://…?servicename=XEPDB1` (forme non dépréciée par DBAL 4.5).
- **Session** : `App\Doctrine\OracleSessionMiddleware` applique le middleware DBAL `InitializeSession`
  (formats NLS des dates et séparateur décimal attendus par Doctrine).
- **Schéma** : tables préfixées `nop_`, identifiants ≤ 30 caractères et sans mot réservé (vérifié par
  `tests/Integration/SchemaPortabilityTest`) ; identifiants par **séquences** `nop_*_id_seq` ; description en
  `CLOB` (jamais indexée, triée ni groupée) ; `TIMESTAMP(0)` en UTC ; booléens `NUMBER(1)`.
- **Migrations par plateforme** dans `migrations/` : SQLite, PostgreSQL et
  [`Version20261023120200_Oracle`](migrations/Version20261023120200_Oracle.php), générée par
  `doctrine:migrations:diff` contre un schéma Oracle vide puis relue ; chacune se désactive (`skipIf`) sur les
  autres bases. Après `migrate`, `doctrine:schema:validate` confirme que le schéma Oracle correspond au mapping.
- **SQL** : DQL portable (pagination `OFFSET … FETCH NEXT` générée par DBAL) et rapports en **SQL natif**
  (`App\Report\NativeSqlReports`) : top 5 des sites (SQL ANSI + `FETCH FIRST 5 ROWS ONLY`) et durée moyenne de
  résolution, dont la variante Oracle est `(CAST(resolved_at AS DATE) - CAST(created_at AS DATE)) * 1440`.
- Particularités gérées : chaîne vide = `NULL` sous Oracle, noms de colonnes renvoyés en majuscules (clés
  ramenées en minuscules), table de suivi des migrations reconnue sans tenir compte de la casse
  (`App\Doctrine\MigrationTableSchemaFilter`).

## Tests et CI

`vendor/bin/phpunit` exécute **294 tests** (suites `unit`, `integration`, `functional`, `api`, `soap`) :
SLA, références, gardes du Workflow, droits par rôle, formulaires, AJAX, transitions, API REST (codes et
format RFC 7807, contrat OpenAPI) et SOAP de bout en bout. `tests/bootstrap.php` recrée la base de test
(schéma + fixtures) à chaque lancement : **toujours utiliser une base ou un schéma dédiés aux tests**.
Chaque test tourne dans une transaction annulée (DAMADoctrineTestBundle).

Scripts Composer : `composer test`, `composer phpstan`, `composer cs`, `composer lint`, `composer qa`.

Pipeline GitHub Actions ([`.github/workflows/ci.yml`](.github/workflows/ci.yml), sur `push` vers `main`, pull
requests et lancement manuel, `permissions: contents: read`) :

| Job | Contenu |
|---|---|
| Qualité | `composer validate --strict`, PHP-CS-Fixer (dry-run), PHPStan niveau 8, `lint:container`, `lint:yaml`, `lint:twig`, `doctrine:schema:validate --skip-sync`, `composer audit` |
| Tests SQLite | migrations + `schema:validate`, puis PHPUnit |
| Tests PostgreSQL 17 | service `postgres:17-alpine`, migrations + `schema:validate`, puis PHPUnit |
| Tests Oracle | service `gvenzl/oracle-xe:21-slim`, image Docker `test` (oci8) : migrations Oracle + `schema:validate`, puis PHPUnit |
| SOAP | tests du groupe `soap`, WSDL servi par un vrai serveur, client d'exemple |
| Docker | build des images prod et nginx, `docker compose up --wait`, test de fumée (`/connexion`, `/api/v1/health`, WSDL : 200 ; API sans clé : 401) |
| Sécurité | gitleaks sur tout l'historique Git |

## Sécurité

- Mots de passe hachés (`auto`), clés d'API stockées sous forme d'empreinte et affichées une seule fois.
- CSRF sur tous les formulaires et sur la déconnexion ; en-tête `X-CSRF-Token` pour les écritures REST en session.
- `login_throttling` (5 essais par minute) ; compte désactivé ⇒ connexion refusée ; contrôle par ressource
  avec des Voters (403).
- Aucun secret versionné : configuration par variables d'environnement, valeurs par défaut factices
  documentées comme telles ; gitleaks en CI.
- Conteneurs non root (`www-data` pour php-fpm, `nginx-unprivileged`), ports publiés sur `127.0.0.1`,
  en-têtes `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `server_tokens off`, seul
  `index.php` est exécutable.

## Limites connues

- Projet de démonstration : pas de notifications (courriel, SMS), pas d'authentification unique, pas de
  pièces jointes ; un seul rôle principal par utilisateur.
- Le temps passé `EN_ATTENTE` ne suspend pas le SLA ; pas de calendrier ouvré.
- Les sessions PHP sont stockées dans le conteneur `app` : elles sont perdues à sa recréation, et plusieurs
  instances de php-fpm demanderaient un stockage partagé (Redis, base).
- L'image de production est préchauffée pour **une** plateforme de base (Oracle par défaut, voir plus haut).
- Images PHP pour `linux/amd64` uniquement ; image ≈ 1,3 Go, dont ≈ 320 Mo pour Instant Client « basic ».
- Oracle XE est limitée (2 Go de RAM, 12 Go de données utilisateur) : suffisant pour la démonstration.
- Les captures d'écran proviennent de la pile e2e sur SQLite, pas de la pile Oracle.

## État d'avancement vérifié

Vérifié localement le 23/10/2026 (Docker Desktop, WSL 2) :

| Élément | Résultat |
|---|---|
| Image PHP : `php -m` | `oci8` (3.3.0, Instant Client 23.26.4), `pdo_pgsql`, `pdo_sqlite`, `soap`, `intl`, `Zend OPcache`, `zip` présents |
| Connexion PHP → Oracle XE 21 | script OCI8 minimal puis Doctrine : OK (schémas `NETOPS` et `NETOPS_TEST`) |
| Migrations | Oracle, PostgreSQL 17 et SQLite : `migrate` sur base vide puis `doctrine:schema:validate` « in sync » |
| PHPUnit sur Oracle | 294 tests, 1628 assertions, 0 échec (1 test ignoré par conception : cas « sans ext-soap ») |
| PHPUnit sur PostgreSQL 17 et SQLite (même image) | 294 tests, 0 échec (même test ignoré) |
| Rapport SQL natif sur Oracle | top 5 des sites et durée moyenne de résolution exécutés sur Oracle (tests d'intégration et tableau de bord) |
| `docker compose up --wait` | pile Oracle + php-fpm + nginx saine en ≈ 80 s sur un volume neuf ; `/connexion`, `/api/v1/health`, WSDL : 200 |
| Parcours sur la pile Oracle | connexion par rôle, déclaration par formulaire, liste AJAX, tableau de bord, création REST (201), transition refusée (409), client SOAP |
| Qualité | PHP-CS-Fixer, PHPStan niveau 8, lints Symfony, `composer validate --strict`, `composer audit` : OK |
| Workflow CI | `actionlint` sans erreur ; commandes des jobs rejouées localement (dont gitleaks) |

**À valider au premier run GitHub** : l'exécution réelle de chaque job sur les runners GitHub (en
particulier le service Oracle, le cache Docker `type=gha` et les durées), et le badge CI ci-dessus.

## Licence

[MIT](LICENSE) © 2026 BELCADI Oussama.
