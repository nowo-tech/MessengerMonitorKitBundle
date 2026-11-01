<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage;
use Nowo\MessengerMonitorKitBundle\Form\HistoryFilterType;
use Nowo\MessengerMonitorKitBundle\Form\MonitorSettingsType;
use Nowo\MessengerMonitorKitBundle\Form\PurgeType;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadNormalizer;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadView;
use Nowo\MessengerMonitorKitBundle\Payload\Redactor;
use Nowo\MessengerMonitorKitBundle\Queue\QueueDepthReport;
use Nowo\MessengerMonitorKitBundle\Queue\TransportDepth;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\OptionalMessage;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPageFactory;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPages;
use Nowo\UiKitBundle\View\Pagination;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\WorkerMetadata;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;
use Zenstruck\Messenger\Monitor\Transport\QueuedMessage;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;

use function http_build_query;
use function time;

/**
 * Page contexts of the REQ-UI-003 contract test, built with {@see MonitorPageFactory} (the service the controllers use)
 * and the real form types. Personal data in the fixtures are obvious placeholders the pages must never print.
 */
final class PageFixtures
{
    /** E-mail of the fixtures; must never reach the HTML (redacted). */
    public const EMAIL = 'jane.doe@example.test';

    /** Token of the fixtures; must never reach the HTML (redacted). */
    public const TOKEN = 'REDACTED-TEST-TOKEN';

    /**
     * @return array<string, mixed>
     */
    public static function dashboard(bool $empty = false): array
    {
        $worker = new WorkerInfo(new WorkerMetadata(['transportNames' => ['async'], 'queueNames' => null]), WorkerInfo::IDLE, time() - 300, 42, 33_554_432);

        return self::factory()->dashboard(
            $empty ? 0 : 120,
            $empty ? 0 : 4,
            35,
            180,
            new QueueDepthReport($empty ? [] : [new TransportDepth('async', 7, false, true), new TransportDepth('failed', 2, true, true), new TransportDepth('amqp', null)]),
            $empty ? [] : [$worker],
            new MonitorSettings(true, false, true),
            $empty ? [] : [self::row(1, true)],
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function history(bool $empty = false): array
    {
        $filters = (new GetFilterFormFactory(FormFactories::factory()))->create(HistoryFilterType::class, ['period' => 'in-last-day', 'per_page' => 25], [
            'action'            => '/history',
            'transport_choices' => ['async', 'failed'],
            'type_choices'      => ['DemoMessage' => DemoMessage::class],
        ])->createView();

        return ['page' => self::factory()->history($empty ? [] : [self::row(1), self::row(2, true)], $filters, new Pagination(1, 25, $empty ? 0 : 2, 1, 'history'), true)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function historyShow(bool $manager = true): array
    {
        $view = new PayloadView(new PayloadNormalizer(), new Redactor());

        return self::factory()->historyShow(
            self::row(2, true),
            [self::row(1, true), self::row(2, true)],
            $manager ? $view->renderData([['handler' => 'App\\Handler', 'data' => ['to' => self::EMAIL, 'id' => 9]]]) : null,
            (new Redactor())->redactText('Payment refused for ' . self::EMAIL),
            $manager,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function transports(): array
    {
        return ['page' => self::factory()->transports(new QueueDepthReport([new TransportDepth('async', 7, false, true), new TransportDepth('failed', 2, true, true)]), true)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function transport(bool $failure = true, bool $empty = false): array
    {
        $messages = $empty ? [] : [self::queued('1', 'async'), self::queued('2', 'gone')];

        return ['page' => self::factory()->transport($failure ? 'failed' : 'async', $messages, $failure, true, 50, 50, ['1' => 'async', '2' => null], ['1'])];
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(bool $manager = true, bool $duplicateRisk = false): array
    {
        $queued = self::queued('1', 'async');
        $view   = new PayloadView(new PayloadNormalizer(), new Redactor());

        return self::factory()->message('failed', $queued, $manager ? $view->render($queued->envelope()->getMessage()) : null, (new Redactor())->redactText('Payment refused for ' . self::EMAIL), true, $manager, 'async', $duplicateRisk);
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(bool $writable = true): array
    {
        $forms    = FormFactories::factory();
        $settings = new MonitorSettings(true, false, true);
        $switches = $writable ? $forms->create(MonitorSettingsType::class, $settings->toArray(), ['action' => '/settings'])->createView() : null;

        return ['page' => self::factory()->settings($switches, $forms->create(PurgeType::class, null, ['action' => '/purge'])->createView(), $settings, 1234, $writable ? new DateTimeImmutable('2026-10-31 03:00:00') : null)];
    }

    public static function factory(?MonitorPages $pages = null): MonitorPageFactory
    {
        return new MonitorPageFactory(self::urlGenerator(), new CsrfOnlyFormFactory(FormFactories::factory()), $pages ?? new MonitorPages());
    }

    public static function row(int $id, bool $failed = false): ProcessedMessage
    {
        $stamp = (new MonitorStamp(new DateTimeImmutable('-2 minutes')))->markReceived('async')->markFinished();

        return (new ProcessedMessage(new Envelope($failed ? new DemoMessage() : new OptionalMessage(), [$stamp]), new Results([]), $failed ? new RuntimeException('Payment refused for ' . self::EMAIL) : null))->setId($id);
    }

    public static function queued(string $id, string $origin): QueuedMessage
    {
        return new QueuedMessage(new Envelope(new DemoMessage(), [
            new TransportMessageIdStamp($id),
            new SentToFailureTransportStamp($origin),
            new MonitorStamp(new DateTimeImmutable('-5 minutes')),
            ErrorDetailsStamp::create(new RuntimeException('Payment refused for ' . self::EMAIL)),
        ]));
    }

    private static function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters));
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }
}
