<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Functional;

use Nowo\MessengerMonitorKitBundle\Event\MessengerMonitorActionEvent;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRoutes;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\OptionalMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\MonitorTestKernel;
use Nowo\MessengerMonitorKitBundle\Tests\Support\ActionRecorder;
use Nowo\MessengerMonitorKitBundle\Tests\Support\Seeder;
use Nowo\MessengerMonitorKitBundle\Ui\ActionForms;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function count;

/**
 * Admin UI end to end on the test kernel: access areas, pages, filters, retry / remove (single and bulk), settings,
 * purge, CSRF and 404 guards, audit events.
 */
final class AdminUiTest extends KernelTestCase
{
    private const PREFIX = '/admin/messenger';

    public function testAccessIsPrivate(): void
    {
        $kernel = $this->bootKernel();

        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', self::PREFIX);
        self::assertSame(401, $anonymous->getResponse()->getStatusCode());

        $viewer = $this->client($kernel, 'viewer');
        $viewer->request('GET', self::PREFIX . '/history');
        self::assertSame(403, $viewer->getResponse()->getStatusCode());
    }

    public function testEveryPageRendersWithData(): void
    {
        $kernel = $this->bootKernel();
        $c      = $this->container($kernel);
        Seeder::history($c, results: [['handler' => 'App\\Handler::__invoke', 'data' => ['to' => 'jane@example.test', 'id' => 5]]]);
        Seeder::history($c, failure: 'Payment refused for jane@example.test');
        $id     = Seeder::failed($c);
        $client = $this->client($kernel);

        $client->request('GET', self::PREFIX);
        $this->assertOk($client, 'mmk-dashboard');
        self::assertStringContainsString('Recent failures', (string) $client->getResponse()->getContent());

        $client->request('GET', self::PREFIX . '/history');
        $this->assertOk($client, 'mmk-history');

        $client->request('GET', self::PREFIX . '/history/2');
        $this->assertOk($client, 'mmk-history-show');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('jane@example.test', $html, 'failure message redacted');
        self::assertStringContainsString('Payment refused for [redacted]', $html);

        $client->request('GET', self::PREFIX . '/history/1');
        self::assertStringContainsString('mmk-results', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('jane@example.test', (string) $client->getResponse()->getContent(), 'results redacted at write time');

        $client->request('GET', self::PREFIX . '/transports');
        $this->assertOk($client, 'mmk-transports');

        $client->request('GET', self::PREFIX . '/transports/failed');
        $this->assertOk($client, 'mmk-transport');
        self::assertStringContainsString('mmk-retry-all', (string) $client->getResponse()->getContent());

        $client->request('GET', self::PREFIX . '/transports/async');
        $this->assertOk($client, 'mmk-transport');
        self::assertStringNotContainsString('mmk-retry', (string) $client->getResponse()->getContent());

        $client->request('GET', self::PREFIX . '/transports/failed/messages/' . $id);
        $this->assertOk($client, 'mmk-payload');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('jane.doe@example.test', $html);
        self::assertStringNotContainsString('REDACTED-TEST-TOKEN', $html);
        self::assertStringContainsString('order-42', $html);

        $client->request('GET', self::PREFIX . '/settings');
        $this->assertOk($client, 'mmk-settings');
    }

    public function testHistoryFiltersAndPagination(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['per_page' => 10]]);
        $c      = $this->container($kernel);
        for ($i = 0; $i < 12; ++$i) {
            Seeder::history($c);
        }
        Seeder::history($c, new OptionalMessage(), 'boom', transport: 'failed');
        $client = $this->client($kernel);

        $client->request('GET', self::PREFIX . '/history', ['status' => 'failed']);
        self::assertSame(1, $this->rows($client));
        $client->request('GET', self::PREFIX . '/history', ['type' => OptionalMessage::class]);
        self::assertSame(1, $this->rows($client));
        $client->request('GET', self::PREFIX . '/history', ['transport' => 'failed', 'status' => 'nope', 'type' => 'Nope']);
        self::assertSame(1, $this->rows($client));
        $client->request('GET', self::PREFIX . '/history', ['per_page' => 33]);
        self::assertSame(10, $this->rows($client));
        $client->request('GET', self::PREFIX . '/history', ['page' => 2, 'per_page' => 10, 'period' => 'all']);
        self::assertSame(3, $this->rows($client));
        $client->request('GET', self::PREFIX . '/history', ['period' => 'today', 'status' => 'success', 'per_page' => 25]);
        self::assertSame(12, $this->rows($client));

        $client->request('GET', self::PREFIX . '/history', ['sort' => 'finished', 'dir' => 'asc', 'status' => 'failed']);
        self::assertSame(1, $this->rows($client));
        self::assertStringContainsString('aria-sort="ascending"', (string) $client->getResponse()->getContent());

        $client->request('GET', self::PREFIX . '/history/999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testRetryAndRemoveOneMessage(): void
    {
        $kernel   = $this->bootKernel(services: [ActionRecorder::class => ActionRecorder::class]);
        $c        = $this->container($kernel);
        $first    = Seeder::failed($c);
        $second   = Seeder::failed($c, new DemoMessage('order-43'));
        $orphan   = Seeder::failed($c, new DemoMessage('order-44'), 'removed_transport');
        $client   = $this->client($kernel);
        $recorder = $c->get(ActionRecorder::class);
        self::assertInstanceOf(ActionRecorder::class, $recorder);

        $this->submit($client, self::PREFIX . '/transports/failed', 'form[action$="/messages/' . $first . '/retry"]');
        self::assertStringContainsString('was sent back to', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->depth($kernel, 'async'));
        self::assertSame(2, $this->depth($kernel, 'failed'));

        $this->submit($client, self::PREFIX . '/transports/failed/messages/' . $second, 'form[action$="/messages/' . $second . '/remove"]');
        self::assertStringContainsString('was removed', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->depth($kernel, 'failed'));

        $client->request('GET', self::PREFIX . '/transports/failed');
        self::assertStringNotContainsString('/messages/' . $orphan . '/retry', (string) $client->getResponse()->getContent(), 'no retry without an original transport');

        self::assertSame([MessengerMonitorActionEvent::RETRIED, MessengerMonitorActionEvent::REMOVED], array_map(static fn (MessengerMonitorActionEvent $e): string => $e->action, $recorder->events));
        self::assertSame(['transport' => 'failed', 'count' => 1, 'types' => ['DemoMessage' => 1], 'targets' => ['async']], $recorder->events[0]->context);
    }

    public function testActionsAreGuarded(): void
    {
        $kernel = $this->bootKernel(['security' => ['manage_roles' => ['ROLE_OPS']], 'retry' => ['duplicate_risk_classes' => [DemoMessage::class]]]);
        $c      = $this->container($kernel);
        $id     = Seeder::failed($c);

        $admin = $this->client($kernel);
        $admin->request('GET', self::PREFIX . '/transports/failed/messages/' . $id);
        self::assertStringNotContainsString('mmk-payload', (string) $admin->getResponse()->getContent(), 'payload for managers only');
        self::assertStringNotContainsString('mmk-retry', (string) $admin->getResponse()->getContent());
        $admin->request('POST', self::PREFIX . '/transports/failed/messages/' . $id . '/retry');
        self::assertSame(403, $admin->getResponse()->getStatusCode());
        $admin->request('GET', self::PREFIX . '/settings');
        self::assertSame(403, $admin->getResponse()->getStatusCode());

        $ops = $this->client($kernel, 'ops');
        $ops->request('GET', self::PREFIX . '/transports/failed');
        self::assertStringContainsString('may already have done part of their work', (string) $ops->getResponse()->getContent());
        $ops->request('POST', self::PREFIX . '/transports/failed/messages/' . $id . '/retry', [ActionForms::formName(MessengerMonitorRoutes::RETRY, ['name' => 'failed', 'id' => $id]) => ['_token' => 'forged']]);
        self::assertSame(403, $ops->getResponse()->getStatusCode(), 'invalid CSRF');

        foreach (['/transports/nope', '/transports/sync', '/transports/failed/messages/999'] as $path) {
            $ops->request('GET', self::PREFIX . $path);
            self::assertSame(404, $ops->getResponse()->getStatusCode(), $path);
        }

        // A non-failure transport is a 404 before any token check.
        $ops->request('POST', self::PREFIX . '/transports/async/retry-all');
        self::assertSame(404, $ops->getResponse()->getStatusCode(), 'bulk actions only target a failure transport');
        // A retry token of one message cannot remove all (SEC-004 L1).
        $ops->request('GET', self::PREFIX . '/transports/failed');
        $field = ActionForms::formName(MessengerMonitorRoutes::RETRY, ['name' => 'failed', 'id' => $id]);
        $token = $ops->getCrawler()->filter('form[action$="/messages/' . $id . '/retry"] input[name="' . $field . '[_token]"]')->attr('value');
        $ops->request('POST', self::PREFIX . '/transports/failed/remove-all', [ActionForms::formName(MessengerMonitorRoutes::REMOVE_ALL, ['name' => 'failed']) => ['_token' => $token], $field => ['_token' => $token]]);
        self::assertSame(403, $ops->getResponse()->getStatusCode());
        self::assertSame(1, $this->depth($kernel, 'failed'));
    }

    public function testBulkActionsAndNonRetryableOutcomes(): void
    {
        $kernel = $this->bootKernel(['retry' => ['batch_limit' => 2]]);
        $c      = $this->container($kernel);
        Seeder::failed($c);
        Seeder::failed($c, new DemoMessage('order-2'));
        Seeder::failed($c, new DemoMessage('order-3'));
        $client = $this->client($kernel);

        $this->submit($client, self::PREFIX . '/transports/failed', 'form[action$="/retry-all"]');
        self::assertStringContainsString('2 messages were sent back', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->depth($kernel, 'failed'));

        $this->submit($client, self::PREFIX . '/transports/failed', 'form[action$="/remove-all"]');
        self::assertStringContainsString('1 messages were removed', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->depth($kernel, 'failed'));

        $orphan = Seeder::failed($c, new DemoMessage('order-4'), 'removed_transport');
        $this->submit($client, self::PREFIX . '/transports/failed', 'form[action$="/retry-all"]');
        self::assertStringContainsString('0 messages were sent back', (string) $client->getResponse()->getContent());

        $client->request('GET', self::PREFIX . '/transports/failed');
        $form = $client->getCrawler()->filter('form[action$="/messages/' . $orphan . '/remove"]')->form();
        $form->getNode()->setAttribute('action', $form->getNode()->getAttribute('action'));
        $client->submit($form);
        $client->followRedirect();
        $client->submit($form);
        $client->followRedirect();
        self::assertStringContainsString('no longer on the failure transport', (string) $client->getResponse()->getContent());
    }

    public function testRetryOfAMessageWithoutOriginIsRefused(): void
    {
        $kernel = $this->bootKernel();
        $c      = $this->container($kernel);
        $orphan = Seeder::failed($c, new DemoMessage('order-5'), 'removed_transport');
        $other  = Seeder::failed($c);
        $client = $this->client($kernel);

        $client->request('GET', self::PREFIX . '/transports/failed');
        // Another message's retry token is refused on the orphan's retry URL (per-message token id, SEC-004 L1).
        $field = ActionForms::formName(MessengerMonitorRoutes::RETRY, ['name' => 'failed', 'id' => $other]);
        $token = $client->getCrawler()->filter('form[action$="/messages/' . $other . '/retry"] input[name="' . $field . '[_token]"]')->attr('value');
        $client->request('POST', self::PREFIX . '/transports/failed/messages/' . $orphan . '/retry', [ActionForms::formName(MessengerMonitorRoutes::RETRY, ['name' => 'failed', 'id' => $orphan]) => ['_token' => $token]]);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        // The origin disappears between the page and the submit: the retry is refused, the message stays parked.
        $client->request('GET', self::PREFIX . '/transports/failed');
        $form      = $client->getCrawler()->filter('form[action$="/messages/' . $other . '/retry"]')->form();
        $transport = $this->container($kernel)->get('messenger.transport.failed');
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);
        $envelope = $transport->find($other);
        self::assertNotNull($envelope);
        $encoded = (new PhpSerializer())->encode($envelope->withoutAll(SentToFailureTransportStamp::class)->withoutAll(TransportMessageIdStamp::class)->with(new SentToFailureTransportStamp('removed_transport')));
        $this->connection($kernel)->update('messenger_messages', ['body' => $encoded['body']], ['id' => $other]);
        $client->submit($form);
        $client->followRedirect();
        self::assertStringContainsString('no original transport', (string) $client->getResponse()->getContent());
    }

    public function testSettingsSwitchRecordingAndPurge(): void
    {
        $kernel = $this->bootKernel(['recording' => ['check_interval' => 0], 'purge' => ['batch_size' => 100, 'max_batches_per_request' => 1]], [ActionRecorder::class => ActionRecorder::class]);
        $c      = $this->container($kernel);
        $client = $this->client($kernel);

        $client->request('GET', self::PREFIX . '/settings');
        $form = $client->getCrawler()->filter('form[name="nowo_mmk_settings"]')->form();
        $client->submit($form, ['nowo_mmk_settings[recording]' => false, 'nowo_mmk_settings[auto_purge]' => '1', 'nowo_mmk_settings[retention]' => '1-week']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $client->followRedirect();
        self::assertStringContainsString('Settings saved.', (string) $client->getResponse()->getContent());

        $store = $c->get(MonitorSettingsStoreInterface::class);
        self::assertInstanceOf(MonitorSettingsStoreInterface::class, $store);
        self::assertFalse($store->load()->recording);
        self::assertTrue($store->load()->autoPurge);
        Seeder::history($c);
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'), 'recording is off');

        // Saving unchanged settings does not audit again.
        $form = $client->getCrawler()->filter('form[name="nowo_mmk_settings"]')->form();
        $client->submit($form);
        $recorder = $c->get(ActionRecorder::class);
        self::assertInstanceOf(ActionRecorder::class, $recorder);
        self::assertCount(1, $recorder->events);

        $client->request('POST', self::PREFIX . '/settings', ['nowo_mmk_settings' => ['recording' => '1']]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'missing CSRF token');

        $store->save(new MonitorSettings());
        for ($i = 0; $i < 150; ++$i) {
            Seeder::history($c);
        }
        $client->request('GET', self::PREFIX . '/settings');
        $client->submit($client->getCrawler()->filter('form[name="nowo_mmk_purge"]')->form(['nowo_mmk_purge[older_than]' => 'all']));
        $client->followRedirect();
        self::assertStringContainsString('100 history rows deleted; more rows match', (string) $client->getResponse()->getContent());
        $client->request('GET', self::PREFIX . '/settings');
        $client->submit($client->getCrawler()->filter('form[name="nowo_mmk_purge"]')->form(['nowo_mmk_purge[older_than]' => 'all']));
        $client->followRedirect();
        self::assertStringContainsString('50 history rows deleted.', (string) $client->getResponse()->getContent());

        $client->request('POST', self::PREFIX . '/purge', ['nowo_mmk_purge' => ['older_than' => 'all']]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testTheConfigStoreIsReadOnly(): void
    {
        $kernel = $this->bootKernel(['settings' => ['store' => 'config'], 'recording' => ['enabled' => false]]);
        $client = $this->client($kernel);

        $client->request('GET', self::PREFIX . '/settings');
        $this->assertOk($client, 'mmk-settings');
        self::assertCount(0, $client->getCrawler()->filter('form[name="nowo_mmk_settings"]'));
        self::assertStringContainsString('recording off', (string) $client->getResponse()->getContent());

        $client->request('POST', self::PREFIX . '/settings');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    private function assertOk(KernelBrowser $client, string $testId): void
    {
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString('data-testid="' . $testId . '"', (string) $client->getResponse()->getContent());
    }

    private function rows(KernelBrowser $client): int
    {
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return count($client->getCrawler()->filter('[data-testid="mmk-history"] tbody tr'));
    }

    private function submit(KernelBrowser $client, string $page, string $selector): void
    {
        $client->request('GET', $page);
        $client->submit($client->getCrawler()->filter($selector)->form());
        self::assertSame(302, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $client->followRedirect();
    }

    private function depth(MonitorTestKernel $kernel, string $transport): int
    {
        $receiver = $this->container($kernel)->get('messenger.transport.' . $transport);
        self::assertInstanceOf(MessageCountAwareInterface::class, $receiver);

        return $receiver->getMessageCount();
    }
}
