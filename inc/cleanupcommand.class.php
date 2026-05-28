<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — CLI cleanup command.
GPLv2+
--------------------------------------------------------------------------
 */

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to purge orphans and trim old stats.
 *
 *   php bin/console mailanalyzer:cleanup
 *   php bin/console mailanalyzer:cleanup --stats-days=90
 *   php bin/console mailanalyzer:cleanup --dry-run
 */
class PluginMailanalyzerCleanupCommand extends Command
{
    protected static $defaultName = 'mailanalyzer:cleanup';

    protected function configure(): void
    {
        $this
            ->setDescription('Purge orphaned message_id rows and old statistics')
            ->setHelp('Removes message_id rows pointing to deleted tickets, and optionally trims old stats entries.')
            ->addOption(
                'stats-days',
                's',
                InputOption::VALUE_REQUIRED,
                'Delete stats older than N days (0 = keep all)',
                0
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show what would be deleted without making changes'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $DB;

        $dryRun    = (bool) $input->getOption('dry-run');
        $statsDays = (int) $input->getOption('stats-days');

        $output->writeln('<info>== MailAnalyzer cleanup ==</info>');
        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — no changes will be made</comment>');
        }
        $output->writeln('');

        // 1) Orphaned message_id rows
        $orphanCount = self::countOrphans();
        $output->writeln("Orphaned message_id rows: <info>$orphanCount</info>");
        if (!$dryRun && $orphanCount > 0) {
            $purged = PluginMailanalyzerStats::purgeOrphans();
            $output->writeln("  Purged: <info>$purged</info>");
        }

        // 2) Optional: trim old stats
        if ($statsDays > 0) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-$statsDays days"));
            $oldCount = (int) ($DB->request([
                'COUNT' => 'cpt',
                'FROM'  => PluginMailanalyzerInstaller::TABLE_STATS,
                'WHERE' => ['date_created' => ['<', $cutoff]],
            ])->current()['cpt'] ?? 0);

            $output->writeln('');
            $output->writeln("Stats older than {$statsDays} days: <info>$oldCount</info>");
            if (!$dryRun && $oldCount > 0) {
                $DB->delete(PluginMailanalyzerInstaller::TABLE_STATS, [
                    'date_created' => ['<', $cutoff],
                ]);
                $output->writeln("  Purged: <info>$oldCount</info>");
            }
        }

        // 3) Summary
        $output->writeln('');
        $output->writeln('<info>== All-time summary ==</info>');
        $summary = PluginMailanalyzerStats::getSummary('all');
        foreach ($summary as $action => $count) {
            $output->writeln(sprintf('  %-22s %s', $action . ':', $count));
        }
        $output->writeln('  ' . str_repeat('-', 32));
        $output->writeln(sprintf('  %-22s %s', 'total:', array_sum($summary)));

        $output->writeln('');
        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }

    private static function countOrphans(): int
    {
        global $DB;
        $res = $DB->request([
            'SELECT' => ['COUNT' => 'm.id AS cnt'],
            'FROM'   => PluginMailanalyzerInstaller::TABLE_MESSAGE_ID . ' AS m',
            'LEFT JOIN' => [
                'glpi_tickets AS t' => [
                    'ON' => ['m' => 'tickets_id', 't' => 'id'],
                ],
            ],
            'WHERE' => [
                'm.tickets_id' => ['!=', 0],
                'TYPE'         => 'AND',
                'OR' => [
                    ['t.id' => null],
                ],
            ],
        ]);
        return (int) ($res->current()['cnt'] ?? 0);
    }
}
