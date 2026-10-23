# NetOps — notes techniques de l'application

Notes de passation (application → Docker / CI / README). La spécification fonctionnelle est dans
[`SPEC.md`](SPEC.md), le contrat REST dans [`openapi.yaml`](openapi.yaml), le contrat SOAP dans
[`../config/soap/incidents.wsdl`](../config/soap/incidents.wsdl).

## Pile

PHP 8.3 · Symfony 7.4 LTS · Doctrine ORM 3.7 / DBAL 4.5 / Migrations 3.9 · Twig + Bootstrap 5.3 (AssetMapper/importmap,
sans build Node) · jQuery 4 (liste AJAX, région → sites) · Vue.js 3.5 (Kanban, build ESM navigateur, composants sans SFC) ·
PHPUnit 12 + DAMADoctrineTestBundle · PHPStan 2 niveau 8 · PHP-CS-Fixer (@Symfony + @Symfony:risky) · Playwright (e2e).

Extensions PHP : `intl`, `mbstring`, `pdo_sqlite` ; `soap` pour l'API SOAP (le WSDL est servi sans) ;
`pdo_pgsql` ou `oci8` selon la base. `ext-soap` est en `suggest` (pas en `require`) pour que `composer install`
fonctionne sur un poste sans l'extension.

## Fichiers `.env`

`.env` et `.env.test` sont versionnés et ne contiennent **aucun secret** (valeurs par défaut de développement et
de test) ; les vrais secrets vont dans `.env.local` (non versionné) ou dans l'environnement (Docker, CI).

- Sans fichier `.env` (image Docker, CI), `bin/console` et `public/index.php` (Symfony Runtime) échouent (`PathException`) :
  lancer avec `APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'` (variable d'environnement, JSON). Toutes les
  variables ont alors une valeur par défaut dans `config/services.yaml` (`env(...)`), SQLite compris.
- `tests/bootstrap.php` et `tests/object-manager.php` ne chargent `.env` que s'il existe.
- Une vraie variable d'environnement (compose, CI) prime toujours sur `.env`.

## Variables d'environnement

| Variable | Défaut | Rôle |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `dev` | `prod` en image |
| `APP_SECRET` | factice en `dev`/`test` uniquement (`when@dev`, `when@test`) | **obligatoire en prod** (CSRF, limitation de connexion) |
| `DATABASE_URL` | `sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db` | PostgreSQL : `postgresql://…?serverVersion=17&charset=utf8` ; Oracle : `oci8://user:pass@host:1521?servicename=XEPDB1&charset=AL32UTF8` (forme non dépréciée par DBAL 4.5) |
| `DEFAULT_URI` | `http://127.0.0.1:8000` | URL hors requête HTTP (commandes) |
| `SLA_HOURS_P1..P4` | `4`, `8`, `24`, `72` | délais SLA (heures) ; les tests supposent ces valeurs |
| `SOAP_WSDL_CACHE` | `0` | option `cache_wsdl` de `\SoapServer` (0 aucun, 1 disque, 2 mémoire, 3 les deux) |
| `NETOPS_TEST_KEEP_DB` | — | `1` = ne pas recréer la base de test au démarrage de PHPUnit |

## Commandes

```bash
composer install
php bin/console importmap:install                 # assets/vendor/ (Bootstrap, Popper, jQuery, Vue) — non versionné
php bin/console doctrine:migrations:migrate -n    # ou doctrine:schema:create
php bin/console doctrine:fixtures:load -n         # données de démonstration (dev/test : DoctrineFixturesBundle)
php -S 127.0.0.1:8000 -t public                   # ou symfony serve ; en dev l'AssetMapper sert /assets à la volée
php bin/console app:api-client:create "Nom du partenaire"   # crée une clé d'API (affichée une fois)
php bin/soap-client-demo.php http://127.0.0.1:8000/soap/incidents?wsdl [clé]   # client SOAP d'exemple (ext-soap)

composer test | phpstan | cs | cs-fix | lint | qa  # scripts Composer (qa = lint + phpstan + cs + test)
```

En prod : `composer install --no-dev`, `importmap:install`, `asset-map:compile` (→ `public/assets/`), `cache:warmup`.
Vérifié : le conteneur prod se compile avec les seules dépendances `--no-dev` (AppFixtures est alors ignorée).
`var/` doit être inscriptible (cache, journaux, et **WSDL résolu** écrit dans `var/cache/<env>/soap/`).

## Comptes et clés de démonstration (fictifs)

Mot de passe **factice** commun : `Demo1234!`

| Compte | Rôle |
|---|---|
| `admin@netops.example` (Nadia Bennani) | ROLE_ADMIN |
| `superviseur@netops.example` (Youssef El Amrani) | ROLE_SUPERVISEUR |
| `technicien1@netops.example` (Karim Tazi), `technicien2@netops.example` (Imane Chraibi) | ROLE_TECHNICIEN |
| `agent1@netops.example` (Omar Idrissi), `agent2@netops.example` (Salma Berrada) | ROLE_AGENT |

Clés d'API factices des fixtures : `nop_demo_cle_factice_pour_tests_locaux_0001` (« OSS Supervision Nord (démo) », active)
et `nop_demo_cle_factice_revoquee_0002` (révoquée → 401). Données : 21 sites (20 actifs, codes fictifs `TNG-0042`…),
40 incidents (6 NOUVEAU, 5 QUALIFIE, 6 ASSIGNE, 6 EN_COURS, 3 EN_ATTENTE, 6 RESOLU, 6 CLOS, 2 ANNULE), 190 événements,
interventions. Génération déterministe, dates relatives à « maintenant ».

## Routes

Web (session, formulaire `/connexion`, CSRF partout, `login_throttling` 5 essais/min) :
`/connexion`, `/deconnexion` (lien avec jeton CSRF), `/` (redirection selon le rôle), `/incidents` (liste ; requête
`X-Requested-With: XMLHttpRequest` → fragment HTML), `/incidents/declarer`, `/incidents/{INC-AAAA-NNNNNN}`,
`POST …/transition/{transition}`, `POST …/assigner`, `POST …/priorite`, `POST …/commentaire`, `POST …/interventions`,
`/mes-interventions`, `POST /interventions/{id}/demarrer|terminer`, `/kanban`, `/tableau-de-bord`,
`/admin/sites`, `/admin/utilisateurs`, `/admin/cles-api`, `/ajax/sites?region=` (JSON).
⚠️ La page de connexion est **`/connexion`** (pas `/login` : le test de fumée de la CI doit viser `/connexion`).

API REST `/api/v1` (RFC 7807, voir `openapi.yaml`) : `GET /health` (public ; 503 si la base ne répond pas —
utilisable comme sonde), `GET /sites`, `GET|POST /incidents`, `GET /incidents/{reference}` (ETag = version),
`POST /incidents/{reference}/transitions/{transition}` (`If-Match`). Authentification : pare-feu sans état
`api_key` (en-tête `X-API-Key`, aucune session créée) ou session (`main`) + `X-CSRF-Token` en écriture.

SOAP : `GET /soap/incidents?wsdl` (WSDL, adresse réelle injectée), `POST /soap/incidents` (SOAP 1.1,
document/literal). Pare-feu désactivé sur `/soap/` : clé vérifiée par le service (en-tête SOAP `<ApiKey>` ou HTTP
`X-API-Key`), fautes `Client.Unauthorized|NotFound|Validation`, `Server`, HTTP 500 pour toute faute (SOAP 1.1).
L'enveloppe est contrôlée avant `\SoapServer` (XML invalide, opération inconnue, en-tête `mustUnderstand` inconnu)
car ext-soap interrompt sinon le processus PHP. Sans ext-soap : `POST` → 503.

## Tests

`vendor/bin/phpunit` — **294 tests** : unit 112, integration 33, functional 81, api 35, soap 33
(suites `--testsuite unit|integration|functional|api|soap` ; les tests SOAP portent aussi `#[Group('soap')]`).
Les 12 tests SOAP de bout en bout ont `#[RequiresPhpExtension('soap')]` (ignorés sans l'extension) ;
`WsdlTest::testPostWithoutTheSoapExtensionIs503` est ignoré quand l'extension est présente.

- `tests/bootstrap.php` recrée la base désignée par `DATABASE_URL` à chaque lancement :
  `doctrine:schema:drop --force --full-database`, `doctrine:schema:create`, `doctrine:fixtures:load`,
  `cache:pool:clear cache.rate_limiter`. ⚠️ Utiliser un schéma/une base **dédiés aux tests** (ex. `netops_test`).
  Les migrations exécutées avant PHPUnit sont donc sans effet sur les tests (le schéma vient du mapping).
- Chaque test tourne dans une transaction annulée (DAMA) : l'ordre des tests est indifférent.
- Résultats obtenus : SQLite local (sans soap) 282 OK + 12 ignorés ; Docker `php:8.3-cli` + soap, SQLite :
  293 OK + 1 ignoré ; même image + PostgreSQL 17 : 293 OK + 1 ignoré.
- PHPStan : `phpstan-platform.php` exclut les 2 fichiers de test qui étendent `\SoapClient` quand ext-soap est absente.
- e2e : `cd e2e && npm ci && npx playwright install chromium && npm run stack && npm test && npm run screenshots; npm run stop`
  (`run-stack.sh` : base `var/data_e2e.db` recréée en dev, puis application servie en **prod** par `php -S` sur
  `127.0.0.1:8090` via `e2e/router.php` ; `stop-stack.sh` retire `public/assets/`). Captures : `docs/screenshots/01…06-*.png`.

## Migrations

Dossier `migrations/`, namespace `DoctrineMigrations`, table de suivi `nop_migration_versions` :
- `Version20261023120000` — SQLite ; `Version20261023120100` — PostgreSQL. Chacune commence `up()`/`down()` par
  `$this->skipIf(!$this->connection->getDatabasePlatform() instanceof SQLitePlatform|PostgreSQLPlatform, …)`.
- Oracle : à ajouter sur le même modèle (`OraclePlatform`), par ex. `Version20261023120200`. Une migration ignorée
  n'est **pas** enregistrée comme exécutée : elle est simplement ré-ignorée à chaque `migrate` (sans effet).
- Vérifié : `migrate` sur base vide puis `doctrine:schema:validate` → « in sync » (SQLite et PostgreSQL 17),
  puis fixtures chargées sur la base migrée.

## Points d'attention Oracle

- **Noms** : tables `nop_*` ; identifiants ≤ 30 caractères et aucun mot réservé Oracle ni PostgreSQL (contrôlé par
  `tests/Integration/SchemaPortabilityTest`). Colonnes renommées : `password_hash`, `role_code`, `comment_text`,
  `report_text`, `site_type`, `source_channel`, `lock_version`, `counter_key`/`current_value`.
- **Identifiants** : stratégie Doctrine par défaut → SEQUENCE sur Oracle (`nop_user_id_seq`, `nop_site_id_seq`,
  `nop_incident_id_seq`, `nop_incident_event_id_seq`, `nop_intervention_id_seq`, `nop_api_client_id_seq`),
  IDENTITY ailleurs. `nop_ref_counter` a une clé primaire VARCHAR(20) (`INC-2026`), sans séquence.
- **Types** : `description` = CLOB (jamais indexé, filtré, trié ni dans un DISTINCT/GROUP BY) ; compte rendu et
  commentaire = VARCHAR2(4000) pour 2000/1000 caractères (sémantique octets par défaut) ; dates `TIMESTAMP(0)` en UTC
  (`date_default_timezone_set('UTC')` dans `Kernel::boot`, affichage `Africa/Casablanca` par Twig) ; booléens
  NUMBER(1) ; énumérations PHP stockées en VARCHAR2 ; `lock_version` NUMBER(10) DEFAULT 1 (`#[ORM\Version]`).
- **Session** : `App\Doctrine\OracleSessionMiddleware` applique le middleware DBAL `InitializeSession`
  (NLS_DATE/TIMESTAMP_FORMAT `YYYY-MM-DD HH24:MI:SS`, NLS_NUMERIC_CHARACTERS `.,`) quand `driver` = `oci8`/`pdo_oci`.
- **Chaîne vide = NULL** sous Oracle : colonnes facultatives nullables, chaînes vides converties en NULL
  (`IncidentEvent`), champs obligatoires validés `NotBlank`.
- **SQL** : DQL portable (`LOWER`, `LIKE … ESCAPE '!'`, `IN`, `SUM(CASE …)`) ; pagination par DBAL
  (`OFFSET … FETCH NEXT` sur Oracle). SQL natif (`App\Report\NativeSqlReports`, documenté dans la classe et affiché
  sur le tableau de bord) : top 5 des sites (SQL ANSI + `modifyLimitQuery`) et durée moyenne de résolution avec une
  variante par plateforme (Oracle : `(CAST(b AS DATE) - CAST(a AS DATE)) * 1440`). Clés de résultat ramenées en
  minuscules (Oracle les renvoie en majuscules). ⚠️ Ces variantes Oracle sont testées au niveau du SQL généré
  (`tests/Unit/Report/NativeSqlPortabilityTest`), **pas encore exécutées sur une base Oracle** : à confirmer par la
  suite PHPUnit sur Oracle.
- **Références `INC-AAAA-NNNNNN`** : `UPDATE nop_ref_counter SET current_value = current_value + 1 …` dans la
  transaction de création (verrou de ligne), insertion de la première ligne de l'année dans un point de sauvegarde
  (nouvel essai si violation d'unicité). Aucune séquence propre à une base.
- `GET /api/v1/health` utilise `getDummySelectSQL()` (`SELECT 1 FROM DUAL` sur Oracle).

## Écarts assumés par rapport à SPEC.md

- Un **rôle principal** par utilisateur (`role_code`) plutôt qu'un tableau JSON de rôles (CLOB sous Oracle, non
  filtrable de façon portable) ; les droits hérités viennent de `role_hierarchy`.
- Déclarant : utilisateur **ou** partenaire API (`api_client_id`) ; canal `source_channel` (WEB, API_REST, API_SOAP).
- REST : 404 pour une transition inconnue ; 403 si la garde refuse pour un droit, 409 pour un statut incompatible,
  un compte rendu manquant ou une version périmée (`If-Match`) ; corps de transition étendu (`assignee`, `report`,
  `priority`) ; un partenaire peut annuler l'incident qu'il a lui-même déclaré (NOUVEAU/QUALIFIE).
- La réassignation (ASSIGNE / EN_COURS / EN_ATTENTE) n'est pas une transition du Workflow : événement ASSIGNATION.
- Un changement de priorité recalcule l'échéance SLA depuis la création ; une réouverture garde l'échéance d'origine.
- Fixtures : 21 sites (dont 1 inactif) ; l'historique est écrit directement (daté dans le passé) sans passer par le Workflow.
