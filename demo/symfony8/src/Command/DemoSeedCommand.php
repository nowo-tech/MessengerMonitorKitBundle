<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SendInvoiceEmail;
use App\Message\SyncInventory;
use App\Message\TrackPageView;
use Doctrine\DBAL\Connection;
use Nowo\MessengerMonitorKitBundle\Settings\CacheMonitorSettingsStore;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

use function sprintf;

/**
 * Demo data: resets the history and the queues, dispatches invoices, inventory syncs and page views (a few of them
 * fail), then runs a worker until the queue is empty, so the history and the failure transport are filled the way
 * production workers fill them. Resets the runtime switches too. Idempotent.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Fill the Messenger history and the failure transport with demo messages')]
final class DemoSeedCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly Connection $connection,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schema = $this->connection->createSchemaManager();
        foreach (['messenger_processed_messages', 'messenger_messages'] as $table) {
            if ($schema->tablesExist([$table])) {
                $this->connection->executeStatement('DELETE FROM ' . $table);
            }
        }

        // Back to the configured switches (the Settings page stores them in the cache pool).
        $this->cache->deleteItem(CacheMonitorSettingsStore::KEY);

        $customers = ['ana.garcia', 'luca.rossi', 'emma.martin', 'jan.devries', 'sofia.costa', 'noah.becker'];
        foreach ($customers as $i => $name) {
            $domain = $i === 4 ? 'example.invalid' : 'example.test';
            $this->bus->dispatch(new SendInvoiceEmail(sprintf('INV-2026-%04d', 101 + $i), $name . '@' . $domain, '+34 600 000 ' . (100 + $i), 4_990 + 1_000 * $i));
        }
        foreach (['SKU-1001' => 12, 'SKU-1002' => 0, 'OLD-0007' => 3, 'SKU-1003' => 40, 'OLD-0042' => 1] as $sku => $quantity) {
            $this->bus->dispatch(new SyncInventory($sku, $quantity));
        }
        foreach (['/', '/pricing', '/docs'] as $path) {
            $this->bus->dispatch(new TrackPageView($path));
        }

        $consume = $this->getApplication()?->find('messenger:consume');
        $consume?->run(new ArrayInput(['receivers' => ['async'], '--limit' => 20, '--time-limit' => 60, '--sleep' => 0.1]), new NullOutput());

        $output->writeln('<info>Demo messages processed: history and failure transport filled.</info>');

        return Command::SUCCESS;
    }
}
