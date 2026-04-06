<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create baseline identity, audit, and worker queue tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, roles JSON NOT NULL, password_hash VARCHAR(255) NOT NULL, failed_attempt_count INT DEFAULT 0 NOT NULL, locked_until DATETIME DEFAULT NULL, created_at_utc DATETIME NOT NULL, updated_at_utc DATETIME NOT NULL, UNIQUE INDEX uniq_user_username (username), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE audit_logs (id INT AUTO_INCREMENT NOT NULL, actor_username VARCHAR(180) DEFAULT NULL, action_type VARCHAR(120) NOT NULL, payload JSON NOT NULL, created_at_utc DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE sensitive_access_logs (id INT AUTO_INCREMENT NOT NULL, actor_username VARCHAR(180) NOT NULL, entity_type VARCHAR(120) NOT NULL, entity_id VARCHAR(120) NOT NULL, field_name VARCHAR(120) NOT NULL, reason VARCHAR(255) NOT NULL, created_at_utc DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE sensitive_access_logs');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE users');
    }
}
