<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuditLogger;
use App\Service\GovernanceLogRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:governance:retention-enforce', description: 'Enforce seven-year retention floor for audit and sensitive-access logs.')]
final class GovernanceLogRetentionCommand extends Command
{
    public function __construct(
        private readonly GovernanceLogRetentionService $retention,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report rows eligible for purge older than retention cutoff.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Delete batch size per statement when purging.', 5000);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = (int) $input->getOption('batch-size');

        $policy = $this->retention->policyMetadata();
        $eligible = $this->retention->eligibleCounts();

        $output->writeln(sprintf(
            'Retention policy: keep at least %d years. Purge-eligible before: %s',
            $policy['minimumRetentionYears'],
            $policy['purgeEligibleBeforeUtc'],
        ));
        $output->writeln(sprintf('Eligible now - audit_logs: %d, sensitive_access_logs: %d', $eligible['auditEligible'], $eligible['sensitiveEligible']));

        if ($dryRun) {
            return Command::SUCCESS;
        }

        $result = $this->retention->purgeExpired($batchSize);
        $output->writeln(sprintf(
            'Purged expired rows - audit_logs: %d, sensitive_access_logs: %d',
            $result['auditDeleted'],
            $result['sensitiveDeleted'],
        ));

        $this->auditLogger->log('admin.governance_retention_purge', 'system_retention', [
            'cutoffUtc' => $result['cutoffUtc'],
            'auditDeleted' => $result['auditDeleted'],
            'sensitiveDeleted' => $result['sensitiveDeleted'],
            'batchSize' => $batchSize,
        ]);

        return Command::SUCCESS;
    }
}
