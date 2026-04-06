<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create practitioner profile and credential review workflow tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE practitioner_profiles (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, lawyer_full_name VARCHAR(180) NOT NULL, firm_name VARCHAR(180) NOT NULL, bar_jurisdiction VARCHAR(120) NOT NULL, license_key_id VARCHAR(80) NOT NULL, license_nonce VARCHAR(255) NOT NULL, license_ciphertext VARCHAR(4096) NOT NULL, license_auth_tag VARCHAR(255) NOT NULL, license_number_masked VARCHAR(64) NOT NULL, created_at_utc DATETIME NOT NULL, updated_at_utc DATETIME NOT NULL, UNIQUE INDEX uniq_practitioner_user (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE practitioner_profiles ADD CONSTRAINT FK_D8A2ED0EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE credential_submissions (id INT AUTO_INCREMENT NOT NULL, practitioner_profile_id INT NOT NULL, label VARCHAR(180) NOT NULL, status VARCHAR(32) NOT NULL, current_version_number INT DEFAULT 0 NOT NULL, created_at_utc DATETIME NOT NULL, updated_at_utc DATETIME NOT NULL, INDEX IDX_5D9895DAB8D91195 (practitioner_profile_id), INDEX idx_credential_submissions_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE credential_submissions ADD CONSTRAINT FK_5D9895DAB8D91195 FOREIGN KEY (practitioner_profile_id) REFERENCES practitioner_profiles (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE credential_submission_versions (id INT AUTO_INCREMENT NOT NULL, submission_id INT NOT NULL, version_number INT NOT NULL, storage_path VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, size_bytes INT NOT NULL, review_status VARCHAR(32) NOT NULL, review_comment LONGTEXT DEFAULT NULL, reviewed_by_username VARCHAR(180) DEFAULT NULL, reviewed_at_utc DATETIME DEFAULT NULL, uploaded_by_username VARCHAR(180) NOT NULL, uploaded_at_utc DATETIME NOT NULL, INDEX IDX_E00DC43F6A9B8A96 (submission_id), INDEX idx_credential_versions_submission_version (submission_id, version_number), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE credential_submission_versions ADD CONSTRAINT FK_E00DC43F6A9B8A96 FOREIGN KEY (submission_id) REFERENCES credential_submissions (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credential_submission_versions DROP FOREIGN KEY FK_E00DC43F6A9B8A96');
        $this->addSql('ALTER TABLE credential_submissions DROP FOREIGN KEY FK_5D9895DAB8D91195');
        $this->addSql('ALTER TABLE practitioner_profiles DROP FOREIGN KEY FK_D8A2ED0EA76ED395');

        $this->addSql('DROP TABLE credential_submission_versions');
        $this->addSql('DROP TABLE credential_submissions');
        $this->addSql('DROP TABLE practitioner_profiles');
    }
}
