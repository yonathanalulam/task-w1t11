<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand scheduling schema with configuration, holds, and booking lifecycle constraints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appointment_slots ADD practitioner_name VARCHAR(180) NOT NULL DEFAULT "Unassigned", ADD location_name VARCHAR(180) NOT NULL DEFAULT "Main Office"');
        $this->addSql('CREATE INDEX idx_appointment_slots_resource_window ON appointment_slots (practitioner_name, location_name, start_at_utc, end_at_utc)');

        $this->addSql('ALTER TABLE appointment_bookings ADD updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD status VARCHAR(32) NOT NULL DEFAULT "ACTIVE", ADD cancelled_by_username VARCHAR(180) DEFAULT NULL, ADD cancellation_reason LONGTEXT DEFAULT NULL, ADD reschedule_count INT NOT NULL DEFAULT 0');
        $this->addSql('CREATE INDEX idx_appointment_bookings_status ON appointment_bookings (status)');

        $this->addSql('CREATE TABLE appointment_holds (id INT AUTO_INCREMENT NOT NULL, slot_id INT NOT NULL, held_by_username VARCHAR(180) NOT NULL, status VARCHAR(32) NOT NULL, created_at_utc DATETIME NOT NULL, expires_at_utc DATETIME NOT NULL, released_at_utc DATETIME DEFAULT NULL, INDEX IDX_91E6AF124A124B6E (slot_id), INDEX idx_appointment_holds_slot_status_expiry (slot_id, status, expires_at_utc), INDEX idx_appointment_holds_user_status (held_by_username, status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE appointment_holds ADD CONSTRAINT FK_91E6AF124A124B6E FOREIGN KEY (slot_id) REFERENCES appointment_slots (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE scheduling_configurations (id INT AUTO_INCREMENT NOT NULL, practitioner_name VARCHAR(180) NOT NULL, location_name VARCHAR(180) NOT NULL, slot_duration_minutes INT NOT NULL DEFAULT 30, slot_capacity INT NOT NULL DEFAULT 1, weekly_availability JSON NOT NULL, created_by_username VARCHAR(180) NOT NULL, updated_by_username VARCHAR(180) NOT NULL, created_at_utc DATETIME NOT NULL, updated_at_utc DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduling_configurations');

        $this->addSql('ALTER TABLE appointment_holds DROP FOREIGN KEY FK_91E6AF124A124B6E');
        $this->addSql('DROP TABLE appointment_holds');

        $this->addSql('DROP INDEX idx_appointment_bookings_status ON appointment_bookings');
        $this->addSql('ALTER TABLE appointment_bookings DROP updated_at_utc, DROP status, DROP cancelled_by_username, DROP cancellation_reason, DROP reschedule_count');

        $this->addSql('DROP INDEX idx_appointment_slots_resource_window ON appointment_slots');
        $this->addSql('ALTER TABLE appointment_slots DROP practitioner_name, DROP location_name');
    }
}
