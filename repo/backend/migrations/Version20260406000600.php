<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add analytics workbench snapshot, feature-definition, and sample-dataset schema with seed data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS analytics_snapshots (id INT AUTO_INCREMENT NOT NULL, occurred_at_utc DATETIME NOT NULL, org_unit VARCHAR(120) NOT NULL, intake_count INT NOT NULL, breach_count INT NOT NULL, escalation_count INT NOT NULL, avg_review_hours DOUBLE PRECISION NOT NULL, resolution_within_sla_pct DOUBLE PRECISION NOT NULL, evidence_completeness_pct DOUBLE PRECISION NOT NULL, INDEX idx_analytics_snapshots_occurred_org (occurred_at_utc, org_unit), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS analytics_feature_definitions (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(160) NOT NULL, description LONGTEXT NOT NULL, tags JSON NOT NULL, formula_expression LONGTEXT NOT NULL, created_by_username VARCHAR(180) NOT NULL, updated_by_username VARCHAR(180) NOT NULL, created_at_utc DATETIME NOT NULL, updated_at_utc DATETIME NOT NULL, INDEX idx_analytics_feature_updated (updated_at_utc), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS analytics_sample_datasets (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, description LONGTEXT NOT NULL, dataset_rows JSON NOT NULL, created_by_username VARCHAR(180) NOT NULL, created_at_utc DATETIME NOT NULL, INDEX idx_analytics_dataset_created (created_at_utc), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('INSERT INTO analytics_snapshots (occurred_at_utc, org_unit, intake_count, breach_count, escalation_count, avg_review_hours, resolution_within_sla_pct, evidence_completeness_pct) VALUES
            ("2026-01-15 00:00:00", "North Region", 122, 7, 6, 21.8, 93.2, 95.4),
            ("2026-01-15 00:00:00", "South Region", 104, 9, 7, 24.5, 90.1, 92.2),
            ("2026-02-15 00:00:00", "North Region", 131, 8, 7, 22.0, 92.1, 94.3),
            ("2026-02-15 00:00:00", "South Region", 98, 7, 5, 20.4, 94.8, 96.1),
            ("2026-03-15 00:00:00", "North Region", 140, 10, 8, 26.2, 89.9, 91.7),
            ("2026-03-15 00:00:00", "South Region", 117, 6, 4, 18.8, 96.0, 97.8),
            ("2026-04-15 00:00:00", "North Region", 146, 7, 5, 19.3, 95.1, 96.2),
            ("2026-04-15 00:00:00", "South Region", 125, 8, 6, 23.1, 92.4, 93.5)');

        $this->addSql(
            'INSERT INTO analytics_feature_definitions (name, description, tags, formula_expression, created_by_username, updated_by_username, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'High-Risk Breach Cluster',
                'Flags rows where compliance breach activity and escalations overlap in high-volume intake windows.',
                json_encode(['high-risk', 'breach', 'escalation'], JSON_THROW_ON_ERROR),
                '(breachCount / intakeCount) * 100 >= 6 AND escalationCount >= 5',
                'system_admin',
                'system_admin',
            ],
        );

        $this->addSql(
            'INSERT INTO analytics_feature_definitions (name, description, tags, formula_expression, created_by_username, updated_by_username, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'Evidence Integrity Sentinel',
                'Tracks cohorts where evidence completeness trends indicate audit fragility.',
                json_encode(['evidence', 'audit', 'integrity'], JSON_THROW_ON_ERROR),
                'evidenceCompletenessPct < 94 OR resolutionWithinSlaPct < 90',
                'system_admin',
                'system_admin',
            ],
        );

        $datasetOneRows = [
            [
                'occurredAtUtc' => '2026-02-03T00:00:00+00:00',
                'orgUnit' => 'North Region',
                'intakeCount' => 32,
                'breachCount' => 3,
                'escalationCount' => 2,
                'avgReviewHours' => 19.6,
                'resolutionWithinSlaPct' => 93.7,
                'evidenceCompletenessPct' => 95.0,
                'tags' => ['high-risk', 'breach', 'sample-a'],
            ],
            [
                'occurredAtUtc' => '2026-03-11T00:00:00+00:00',
                'orgUnit' => 'South Region',
                'intakeCount' => 28,
                'breachCount' => 1,
                'escalationCount' => 1,
                'avgReviewHours' => 14.4,
                'resolutionWithinSlaPct' => 97.5,
                'evidenceCompletenessPct' => 98.2,
                'tags' => ['audit', 'integrity', 'sample-a'],
            ],
            [
                'occurredAtUtc' => '2026-04-02T00:00:00+00:00',
                'orgUnit' => 'North Region',
                'intakeCount' => 35,
                'breachCount' => 4,
                'escalationCount' => 3,
                'avgReviewHours' => 22.2,
                'resolutionWithinSlaPct' => 90.8,
                'evidenceCompletenessPct' => 92.6,
                'tags' => ['high-risk', 'audit', 'sample-a'],
            ],
        ];

        $datasetTwoRows = [
            [
                'occurredAtUtc' => '2026-01-19T00:00:00+00:00',
                'orgUnit' => 'South Region',
                'intakeCount' => 26,
                'breachCount' => 2,
                'escalationCount' => 2,
                'avgReviewHours' => 17.5,
                'resolutionWithinSlaPct' => 94.0,
                'evidenceCompletenessPct' => 93.1,
                'tags' => ['escalation', 'breach', 'sample-b'],
            ],
            [
                'occurredAtUtc' => '2026-03-21T00:00:00+00:00',
                'orgUnit' => 'North Region',
                'intakeCount' => 30,
                'breachCount' => 5,
                'escalationCount' => 4,
                'avgReviewHours' => 27.3,
                'resolutionWithinSlaPct' => 87.2,
                'evidenceCompletenessPct' => 89.9,
                'tags' => ['high-risk', 'evidence', 'sample-b'],
            ],
            [
                'occurredAtUtc' => '2026-04-08T00:00:00+00:00',
                'orgUnit' => 'South Region',
                'intakeCount' => 31,
                'breachCount' => 2,
                'escalationCount' => 1,
                'avgReviewHours' => 16.2,
                'resolutionWithinSlaPct' => 95.9,
                'evidenceCompletenessPct' => 97.0,
                'tags' => ['integrity', 'audit', 'sample-b'],
            ],
        ];

        $this->addSql(
            'INSERT INTO analytics_sample_datasets (name, description, dataset_rows, created_by_username, created_at_utc) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                'Cross-Region Escalation Sample A',
                'Sample dataset for analyst what-if analysis focused on escalation and evidence controls.',
                json_encode($datasetOneRows, JSON_THROW_ON_ERROR),
                'system_admin',
            ],
        );

        $this->addSql(
            'INSERT INTO analytics_sample_datasets (name, description, dataset_rows, created_by_username, created_at_utc) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                'Cross-Region Escalation Sample B',
                'Secondary dataset for comparative compliance trend and correlation analysis.',
                json_encode($datasetTwoRows, JSON_THROW_ON_ERROR),
                'system_admin',
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE analytics_sample_datasets');
        $this->addSql('DROP TABLE analytics_feature_definitions');
        $this->addSql('DROP TABLE analytics_snapshots');
    }
}
