<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial NetOps pour SQLite (généré par doctrine:migrations:diff puis relu).
 *
 * Une migration par plateforme dans le même dossier : chacune est ignorée (skipIf) quand la base
 * courante n'est pas la sienne. Le schéma de référence reste le mapping des entités (src/Entity).
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'NetOps : schéma initial (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof SQLitePlatform, 'Migration réservée à SQLite.');

        $this->addSql('CREATE TABLE nop_api_client (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, key_prefix VARCHAR(16) NOT NULL, key_hash VARCHAR(64) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_api_client_name ON nop_api_client (name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_api_client_hash ON nop_api_client (key_hash)');
        $this->addSql('CREATE TABLE nop_incident (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, reference VARCHAR(20) NOT NULL, title VARCHAR(150) NOT NULL, description CLOB NOT NULL, priority VARCHAR(2) NOT NULL, status VARCHAR(20) NOT NULL, category VARCHAR(20) NOT NULL, source_channel VARCHAR(10) NOT NULL, sla_due_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, lock_version INTEGER DEFAULT 1 NOT NULL, site_id INTEGER NOT NULL, reporter_id INTEGER DEFAULT NULL, api_client_id INTEGER DEFAULT NULL, assignee_id INTEGER DEFAULT NULL, CONSTRAINT FK_7A196ECBF6BD1646 FOREIGN KEY (site_id) REFERENCES nop_site (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A196ECBE1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES nop_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A196ECB643FCC5A FOREIGN KEY (api_client_id) REFERENCES nop_api_client (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A196ECB59EC7D60 FOREIGN KEY (assignee_id) REFERENCES nop_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_nop_incident_status ON nop_incident (status)');
        $this->addSql('CREATE INDEX idx_nop_incident_prio ON nop_incident (priority)');
        $this->addSql('CREATE INDEX idx_nop_incident_due ON nop_incident (sla_due_at)');
        $this->addSql('CREATE INDEX idx_nop_incident_created ON nop_incident (created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_incident_ref ON nop_incident (reference)');
        $this->addSql('CREATE INDEX IDX_7A196ECBF6BD1646 ON nop_incident (site_id)');
        $this->addSql('CREATE INDEX IDX_7A196ECBE1CFE6F5 ON nop_incident (reporter_id)');
        $this->addSql('CREATE INDEX IDX_7A196ECB643FCC5A ON nop_incident (api_client_id)');
        $this->addSql('CREATE INDEX IDX_7A196ECB59EC7D60 ON nop_incident (assignee_id)');
        $this->addSql('CREATE TABLE nop_incident_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, event_type VARCHAR(20) NOT NULL, from_status VARCHAR(20) DEFAULT NULL, to_status VARCHAR(20) DEFAULT NULL, author_label VARCHAR(150) NOT NULL, comment_text VARCHAR(4000) DEFAULT NULL, created_at DATETIME NOT NULL, incident_id INTEGER NOT NULL, author_id INTEGER DEFAULT NULL, CONSTRAINT FK_98CC34F359E53FB9 FOREIGN KEY (incident_id) REFERENCES nop_incident (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_98CC34F3F675F31B FOREIGN KEY (author_id) REFERENCES nop_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_nop_event_created ON nop_incident_event (created_at)');
        $this->addSql('CREATE INDEX IDX_98CC34F359E53FB9 ON nop_incident_event (incident_id)');
        $this->addSql('CREATE INDEX IDX_98CC34F3F675F31B ON nop_incident_event (author_id)');
        $this->addSql('CREATE TABLE nop_intervention (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, planned_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, report_text VARCHAR(4000) DEFAULT NULL, duration_min INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, incident_id INTEGER NOT NULL, technician_id INTEGER NOT NULL, CONSTRAINT FK_8D9635C259E53FB9 FOREIGN KEY (incident_id) REFERENCES nop_incident (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_8D9635C2E6C5D496 FOREIGN KEY (technician_id) REFERENCES nop_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_nop_interv_planned ON nop_intervention (planned_at)');
        $this->addSql('CREATE INDEX IDX_8D9635C259E53FB9 ON nop_intervention (incident_id)');
        $this->addSql('CREATE INDEX IDX_8D9635C2E6C5D496 ON nop_intervention (technician_id)');
        $this->addSql('CREATE TABLE nop_ref_counter (counter_key VARCHAR(20) NOT NULL, current_value INTEGER NOT NULL, PRIMARY KEY (counter_key))');
        $this->addSql('CREATE TABLE nop_site (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(20) NOT NULL, name VARCHAR(150) NOT NULL, city VARCHAR(100) NOT NULL, region VARCHAR(100) NOT NULL, site_type VARCHAR(20) NOT NULL, is_active BOOLEAN NOT NULL)');
        $this->addSql('CREATE INDEX idx_nop_site_region ON nop_site (region)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_site_code ON nop_site (code)');
        $this->addSql('CREATE TABLE nop_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, role_code VARCHAR(30) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_nop_user_role ON nop_user (role_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_user_email ON nop_user (email)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof SQLitePlatform, 'Migration réservée à SQLite.');

        $this->addSql('DROP TABLE nop_api_client');
        $this->addSql('DROP TABLE nop_incident');
        $this->addSql('DROP TABLE nop_incident_event');
        $this->addSql('DROP TABLE nop_intervention');
        $this->addSql('DROP TABLE nop_ref_counter');
        $this->addSql('DROP TABLE nop_site');
        $this->addSql('DROP TABLE nop_user');
    }
}
