<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Command;

use Nowo\MessengerMonitorKitBundle\History\AutoPurger;
use Nowo\MessengerMonitorKitBundle\History\HistoryPurger;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\History\PurgeResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function implode;
use function is_string;
use function sprintf;

/**
 * Batched purge of the Messenger history.
 *
 * `--auto` applies the runtime retention when the automatic purge is on and due (schedule it every 15 minutes with
 * cron or Symfony Scheduler; `--force` ignores the interval). Without `--auto` it purges `--older-than` (default one
 * month), keeping failed rows unless `--include-failed`.
 */
#[AsCommand(name: self::NAME, description: 'Purge Messenger monitor history in batches (manual period or the runtime retention with --auto)')]
final class PurgeHistoryCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:messenger-monitor:purge';

    /**
     * @param HistoryPurger $purger batched purge
     * @param AutoPurger $autoPurger runtime retention
     */
    public function __construct(
        private readonly HistoryPurger $purger,
        private readonly AutoPurger $autoPurger,
    ) {
        parent::__construct();
    }

    /**
     * Options: --auto, --force, --older-than, --include-failed.
     */
    protected function configure(): void
    {
        $values = implode(', ', array_map(static fn (PurgePeriod $period): string => $period->value, PurgePeriod::cases()));
        $this
            ->addOption('auto', null, InputOption::VALUE_NONE, 'Apply the runtime retention when the automatic purge is on and due')
            ->addOption('force', null, InputOption::VALUE_NONE, 'With --auto: run even when the interval has not elapsed (still needs the automatic purge on)')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Manual purge: ' . $values, PurgePeriod::OneMonth->value)
            ->addOption('include-failed', null, InputOption::VALUE_NONE, 'Manual purge: also delete failed rows');
    }

    /**
     * Runs the purge.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('auto') === true) {
            $result = $this->autoPurger->run($input->getOption('force') === true && $this->autoPurger->isEnabled());
            if (!$result instanceof PurgeResult) {
                $io->writeln('Messenger monitor automatic purge not due (or switched off).', OutputInterface::VERBOSITY_VERBOSE);

                return Command::SUCCESS;
            }
            $io->success(sprintf('Messenger monitor: %d history row(s) purged (automatic retention).', $result->deleted));

            return Command::SUCCESS;
        }

        $value  = $input->getOption('older-than');
        $period = is_string($value) ? PurgePeriod::tryFrom($value) : null;
        if ($period === null) {
            $io->error(sprintf('Invalid --older-than value; use one of: %s.', implode(', ', array_map(static fn (PurgePeriod $p): string => $p->value, PurgePeriod::cases()))));

            return Command::INVALID;
        }

        $result = $this->purger->purge($period, $input->getOption('include-failed') !== true);
        $io->success(sprintf('Messenger monitor: %d history row(s) purged.', $result->deleted));

        return Command::SUCCESS;
    }
}
