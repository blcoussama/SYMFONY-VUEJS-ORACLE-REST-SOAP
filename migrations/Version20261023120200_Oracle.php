<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial NetOps pour Oracle (18c et suivants ; vérifié sur Oracle XE 21).
 *
 * Généré par doctrine:migrations:diff contre un schéma Oracle vide (pilote oci8), puis relu :
 * - identifiants par séquence (nop_*_id_seq), stratégie Doctrine par défaut sur Oracle ;
 * - CLOB pour la description, VARCHAR2(4000) pour commentaires et comptes rendus ;
 * - TIMESTAMP(0) en UTC, booléens NUMBER(1), entiers NUMBER(10) ;
 * - noms courts (<= 30 caractères) et sans mot réservé Oracle.
 *
 * Une migration par plateforme dans le même dossier : celle-ci est ignorée (skipIf) sur toute
 * autre base. Le schéma de référence reste le mapping des entités (src/Entity).
 */
final class Version20261023120200_Oracle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'NetOps : schéma initial (Oracle)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof OraclePlatform, 'Migration réservée à Oracle.');

        $this->addSql('CREATE SEQUENCE nop_api_client_id_seq START WITH 1 MINVALUE 1 INCREMENT BY 1');
        $this->addSql('CREATE SEQUENCE nop_incident_id_seq START WITH 1 MINVALUE 1 INCREMENT BY 1');
        $this->addSql('CREATE SEQUENCE nop_incident_event_id_seq START WITH 1 MINVALUE 1 INCREMENT BY 1');
        $this->addSql('CREATE SEQUENCE nop_intervention_id_seq START WITH 1 MINVALUE 1 INCREMENT BY 1');
        $this->addSql('CREATE SEQUENCE nop_site_id_seq START WITH 1 MINVALUE 1 INCREMENT BY 1');
        $this->addSql('CREATE SEQUENCE nop_user_id_seq START WITH 1 MINVALUE 1 INCREMENT BY 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE nop_api_client (
              id NUMBER(10) NOT NULL,
              name VARCHAR2(100) NOT NULL,
              key_prefix VARCHAR2(16) NOT NULL,
              key_hash VARCHAR2(64) NOT NULL,
              is_active NUMBER(1) NOT NULL,
              created_at TIMESTAMP(0) NOT NULL,
              last_used_at TIMESTAMP(0) DEFAULT NULL NULL,
              revoked_at TIMESTAMP(0) DEFAULT NULL NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_api_client_name ON nop_api_client (name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_api_client_hash ON nop_api_client (key_hash)');
        $this->addSql(<<<'SQL'
            CREATE TABLE nop_incident (
              id NUMBER(10) NOT NULL,
              reference VARCHAR2(20) NOT NULL,
              title VARCHAR2(150) NOT NULL,
              description CLOB NOT NULL,
              priority VARCHAR2(2) NOT NULL,
              status VARCHAR2(20) NOT NULL,
              category VARCHAR2(20) NOT NULL,
              source_channel VARCHAR2(10) NOT NULL,
              sla_due_at TIMESTAMP(0) NOT NULL,
              created_at TIMESTAMP(0) NOT NULL,
              updated_at TIMESTAMP(0) NOT NULL,
              resolved_at TIMESTAMP(0) DEFAULT NULL NULL,
              closed_at TIMESTAMP(0) DEFAULT NULL NULL,
              lock_version NUMBER(10) DEFAULT 1 NOT NULL,
              site_id NUMBER(10) NOT NULL,
              reporter_id NUMBER(10) DEFAULT NULL NULL,
              api_client_id NUMBER(10) DEFAULT NULL NULL,
              assignee_id NUMBER(10) DEFAULT NULL NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_nop_incident_status ON nop_incident (status)');
        $this->addSql('CREATE INDEX idx_nop_incident_prio ON nop_incident (priority)');
        $this->addSql('CREATE INDEX idx_nop_incident_due ON nop_incident (sla_due_at)');
        $this->addSql('CREATE INDEX idx_nop_incident_created ON nop_incident (created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_incident_ref ON nop_incident (reference)');
        $this->addSql('CREATE INDEX IDX_7A196ECBF6BD1646 ON nop_incident (site_id)');
        $this->addSql('CREATE INDEX IDX_7A196ECBE1CFE6F5 ON nop_incident (reporter_id)');
        $this->addSql('CREATE INDEX IDX_7A196ECB643FCC5A ON nop_incident (api_client_id)');
        $this->addSql('CREATE INDEX IDX_7A196ECB59EC7D60 ON nop_incident (assignee_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE nop_incident_event (
              id NUMBER(10) NOT NULL,
              event_type VARCHAR2(20) NOT NULL,
              from_status VARCHAR2(20) DEFAULT NULL NULL,
              to_status VARCHAR2(20) DEFAULT NULL NULL,
              author_label VARCHAR2(150) NOT NULL,
              comment_text VARCHAR2(4000) DEFAULT NULL NULL,
              created_at TIMESTAMP(0) NOT NULL,
              incident_id NUMBER(10) NOT NULL,
              author_id NUMBER(10) DEFAULT NULL NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_nop_event_created ON nop_incident_event (created_at)');
        $this->addSql('CREATE INDEX IDX_98CC34F359E53FB9 ON nop_incident_event (incident_id)');
        $this->addSql('CREATE INDEX IDX_98CC34F3F675F31B ON nop_incident_event (author_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE nop_intervention (
              id NUMBER(10) NOT NULL,
              planned_at TIMESTAMP(0) NOT NULL,
              started_at TIMESTAMP(0) DEFAULT NULL NULL,
              ended_at TIMESTAMP(0) DEFAULT NULL NULL,
              report_text VARCHAR2(4000) DEFAULT NULL NULL,
              duration_min NUMBER(10) DEFAULT NULL NULL,
              created_at TIMESTAMP(0) NOT NULL,
              incident_id NUMBER(10) NOT NULL,
              technician_id NUMBER(10) NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_nop_interv_planned ON nop_intervention (planned_at)');
        $this->addSql('CREATE INDEX IDX_8D9635C259E53FB9 ON nop_intervention (incident_id)');
        $this->addSql('CREATE INDEX IDX_8D9635C2E6C5D496 ON nop_intervention (technician_id)');
        $this->addSql('CREATE TABLE nop_ref_counter (counter_key VARCHAR2(20) NOT NULL, current_value NUMBER(10) NOT NULL, PRIMARY KEY (counter_key))');
        $this->addSql(<<<'SQL'
            CREATE TABLE nop_site (
              id NUMBER(10) NOT NULL,
              code VARCHAR2(20) NOT NULL,
              name VARCHAR2(150) NOT NULL,
              city VARCHAR2(100) NOT NULL,
              region VARCHAR2(100) NOT NULL,
              site_type VARCHAR2(20) NOT NULL,
              is_active NUMBER(1) NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_nop_site_region ON nop_site (region)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_site_code ON nop_site (code)');
        $this->addSql(<<<'SQL'
            CREATE TABLE nop_user (
              id NUMBER(10) NOT NULL,
              email VARCHAR2(180) NOT NULL,
              first_name VARCHAR2(100) NOT NULL,
              last_name VARCHAR2(100) NOT NULL,
              password_hash VARCHAR2(255) NOT NULL,
              role_code VARCHAR2(30) NOT NULL,
              is_active NUMBER(1) NOT NULL,
              created_at TIMESTAMP(0) NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_nop_user_role ON nop_user (role_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_nop_user_email ON nop_user (email)');
        $this->addSql('ALTER TABLE nop_incident ADD CONSTRAINT FK_7A196ECBF6BD1646 FOREIGN KEY (site_id) REFERENCES nop_site (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE nop_incident ADD CONSTRAINT FK_7A196ECBE1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES nop_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE nop_incident ADD CONSTRAINT FK_7A196ECB643FCC5A FOREIGN KEY (api_client_id) REFERENCES nop_api_client (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE nop_incident ADD CONSTRAINT FK_7A196ECB59EC7D60 FOREIGN KEY (assignee_id) REFERENCES nop_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE nop_incident_event ADD CONSTRAINT FK_98CC34F359E53FB9 FOREIGN KEY (incident_id) REFERENCES nop_incident (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE nop_incident_event ADD CONSTRAINT FK_98CC34F3F675F31B FOREIGN KEY (author_id) REFERENCES nop_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE nop_intervention ADD CONSTRAINT FK_8D9635C259E53FB9 FOREIGN KEY (incident_id) REFERENCES nop_incident (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE nop_intervention ADD CONSTRAINT FK_8D9635C2E6C5D496 FOREIGN KEY (technician_id) REFERENCES nop_user (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof OraclePlatform, 'Migration réservée à Oracle.');

        $this->addSql('DROP SEQUENCE nop_api_client_id_seq');
        $this->addSql('DROP SEQUENCE nop_incident_id_seq');
        $this->addSql('DROP SEQUENCE nop_incident_event_id_seq');
        $this->addSql('DROP SEQUENCE nop_intervention_id_seq');
        $this->addSql('DROP SEQUENCE nop_site_id_seq');
        $this->addSql('DROP SEQUENCE nop_user_id_seq');
        $this->addSql('ALTER TABLE nop_incident DROP CONSTRAINT FK_7A196ECBF6BD1646');
        $this->addSql('ALTER TABLE nop_incident DROP CONSTRAINT FK_7A196ECBE1CFE6F5');
        $this->addSql('ALTER TABLE nop_incident DROP CONSTRAINT FK_7A196ECB643FCC5A');
        $this->addSql('ALTER TABLE nop_incident DROP CONSTRAINT FK_7A196ECB59EC7D60');
        $this->addSql('ALTER TABLE nop_incident_event DROP CONSTRAINT FK_98CC34F359E53FB9');
        $this->addSql('ALTER TABLE nop_incident_event DROP CONSTRAINT FK_98CC34F3F675F31B');
        $this->addSql('ALTER TABLE nop_intervention DROP CONSTRAINT FK_8D9635C259E53FB9');
        $this->addSql('ALTER TABLE nop_intervention DROP CONSTRAINT FK_8D9635C2E6C5D496');
        $this->addSql('DROP TABLE nop_api_client');
        $this->addSql('DROP TABLE nop_incident');
        $this->addSql('DROP TABLE nop_incident_event');
        $this->addSql('DROP TABLE nop_intervention');
        $this->addSql('DROP TABLE nop_ref_counter');
        $this->addSql('DROP TABLE nop_site');
        $this->addSql('DROP TABLE nop_user');
    }
}
