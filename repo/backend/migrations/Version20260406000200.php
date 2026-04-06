<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create appointment slot and booking tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE appointment_slots (id INT AUTO_INCREMENT NOT NULL, start_at_utc DATETIME NOT NULL, end_at_utc DATETIME NOT NULL, capacity INT NOT NULL, booked_count INT DEFAULT 0 NOT NULL, status VARCHAR(32) NOT NULL, created_by_username VARCHAR(180) NOT NULL, created_at_utc DATETIME NOT NULL, updated_at_utc DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE appointment_bookings (id INT AUTO_INCREMENT NOT NULL, slot_id INT NOT NULL, booked_by_username VARCHAR(180) NOT NULL, created_at_utc DATETIME NOT NULL, cancelled_at_utc DATETIME DEFAULT NULL, INDEX IDX_88E0248D4A124B6E (slot_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE appointment_bookings ADD CONSTRAINT FK_88E0248D4A124B6E FOREIGN KEY (slot_id) REFERENCES appointment_slots (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appointment_bookings DROP FOREIGN KEY FK_88E0248D4A124B6E');
        $this->addSql('DROP TABLE appointment_bookings');
        $this->addSql('DROP TABLE appointment_slots');
    }
}
