<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created-at indexes for governance retention operations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_audit_logs_created_at_utc ON audit_logs (created_at_utc)');
        $this->addSql('CREATE INDEX idx_sensitive_logs_created_at_utc ON sensitive_access_logs (created_at_utc)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_logs_created_at_utc ON audit_logs');
        $this->addSql('DROP INDEX idx_sensitive_logs_created_at_utc ON sensitive_access_logs');
    }
}
