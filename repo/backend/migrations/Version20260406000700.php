<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin anomaly alert persistence table for governance workflow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_anomaly_alerts (id INT AUTO_INCREMENT NOT NULL, alert_type VARCHAR(120) NOT NULL, scope_key VARCHAR(190) NOT NULL, status VARCHAR(24) NOT NULL, payload JSON NOT NULL, created_at_utc DATETIME NOT NULL, updated_at_utc DATETIME NOT NULL, last_detected_at_utc DATETIME NOT NULL, acknowledged_at_utc DATETIME DEFAULT NULL, acknowledged_by_username VARCHAR(180) DEFAULT NULL, acknowledgement_note LONGTEXT DEFAULT NULL, resolved_at_utc DATETIME DEFAULT NULL, UNIQUE INDEX uniq_admin_alert_type_scope (alert_type, scope_key), INDEX idx_admin_alert_status_detected (status, last_detected_at_utc), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_anomaly_alerts');
    }
}
