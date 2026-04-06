<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create controlled content question-bank tables with version and asset support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE question_bank_assets (id INT AUTO_INCREMENT NOT NULL, storage_path VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, size_bytes INT NOT NULL, uploaded_by_username VARCHAR(180) NOT NULL, created_at_utc DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE question_bank_entries (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(240) NOT NULL, plain_text_content LONGTEXT NOT NULL, rich_text_content LONGTEXT NOT NULL, tags JSON NOT NULL, difficulty INT NOT NULL, formulas JSON NOT NULL, embedded_images JSON NOT NULL, status VARCHAR(32) NOT NULL, duplicate_review_state VARCHAR(32) NOT NULL, current_version_number INT NOT NULL, created_by_username VARCHAR(180) NOT NULL, updated_by_username VARCHAR(180) NOT NULL, published_by_username VARCHAR(180) DEFAULT NULL, created_at_utc DATETIME NOT NULL, updated_at_utc DATETIME NOT NULL, published_at_utc DATETIME DEFAULT NULL, offline_at_utc DATETIME DEFAULT NULL, INDEX idx_question_bank_status (status), INDEX idx_question_bank_updated (updated_at_utc), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE question_bank_entry_versions (id INT AUTO_INCREMENT NOT NULL, entry_id INT NOT NULL, version_number INT NOT NULL, title VARCHAR(240) NOT NULL, plain_text_content LONGTEXT NOT NULL, rich_text_content LONGTEXT NOT NULL, tags JSON NOT NULL, difficulty INT NOT NULL, formulas JSON NOT NULL, embedded_images JSON NOT NULL, created_by_username VARCHAR(180) NOT NULL, created_at_utc DATETIME NOT NULL, change_note LONGTEXT DEFAULT NULL, INDEX IDX_E478C530BA364942 (entry_id), INDEX idx_question_entry_version_lookup (entry_id, version_number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE question_bank_entry_versions ADD CONSTRAINT FK_E478C530BA364942 FOREIGN KEY (entry_id) REFERENCES question_bank_entries (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE question_bank_entry_versions DROP FOREIGN KEY FK_E478C530BA364942');

        $this->addSql('DROP TABLE question_bank_entry_versions');
        $this->addSql('DROP TABLE question_bank_entries');
        $this->addSql('DROP TABLE question_bank_assets');
    }
}
