<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Security;

use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\MessengerIntegrationPass;
use Nowo\MessengerMonitorKitBundle\Messenger\FailedTransportDsnEnvVarProcessor;
use Nowo\MessengerMonitorKitBundle\Payload\KeyPatternRedactor;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadNormalizer;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadView;
use Nowo\MessengerMonitorKitBundle\Payload\Redactor;
use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\OptionalMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Support\MemoryTransport;
use Nowo\MessengerMonitorKitBundle\Tests\Support\Monitor;
use Nowo\MessengerMonitorKitBundle\Tests\Support\RecordingBus;
use Nowo\MessengerMonitorKitBundle\Ui\ActionForms;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use SensitiveParameter;
use Stringable;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

use function array_unique;
use function class_exists;
use function dirname;
use function file_get_contents;
use function is_file;
use function str_repeat;
use function strlen;

use const PHP_VERSION_ID;

/**
 * Regression tests for the REQ-SEC-004 audit findings (Appendix CB, 2026-11-01), adapted from the audit PoC.
 */
final class SecurityAuditRegressionTest extends TestCase
{
    private const SECRET = 'REDACTED-TEST-TOKEN';

    /** M1: credentials in free text (failure messages, descriptions) are always masked. */
    #[Test]
    public function credentialsInFreeTextAreMasked(): void
    {
        $redactor = new KeyPatternRedactor(maskEmails: false, maskLongNumbers: false);
        $text     = 'SQLSTATE[08006] connection to mysql://app:' . self::SECRET . '@10.0.0.5/app failed; Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln; Basic dXNlcjpwYXNz; '
            . 'api_key=sk_live_PLACEHOLDER0; password=' . self::SECRET . '; pwd: "two words"; token=\'' . self::SECRET . '\'; key=' . self::SECRET . ' '
            . 'ghp_PLACEHOLDER00000000 xoxb-000-PLACEHOLDER AKIA0000000000PLACEH0 pk_test_PLACEHOLDER';
        $redacted = $redactor->redactText($text);

        self::assertStringNotContainsString(self::SECRET, $redacted);
        foreach (['eyJhbGciOiJIUzI1NiJ9', 'dXNlcjpwYXNz', 'sk_live_', 'two words', 'ghp_', 'xoxb-', 'AKIA', 'pk_test_'] as $needle) {
            self::assertStringNotContainsString($needle, $redacted, $needle);
        }
        self::assertStringContainsString('mysql://[redacted]@10.0.0.5/app', $redacted);
        self::assertStringContainsString('Bearer [redacted]', $redacted);
        self::assertStringContainsString('password=[redacted]', $redacted);
        self::assertSame('monkey=banana', $redactor->redactText('monkey=banana'), 'a word ending in "key" is not a key pair');
    }

    /** L3: national ids, numbers with `.` / `/` / parentheses, Unicode e-mails; ISO dates and invoice numbers kept. */
    #[Test]
    public function freeTextPatternsCoverIdsNumbersAndUnicodeEmails(): void
    {
        $redactor = new KeyPatternRedactor();

        self::assertSame('DNI [redacted] NIE [redacted]', $redactor->redactText('DNI 12345678Z NIE X1234567L'));
        self::assertSame('card [redacted] phone [redacted] iban [redacted]', $redactor->redactText('card 4111.1111.1111.1111 phone (612) 345-678 iban 0049/1500/0512'));
        self::assertSame('[redacted] and [redacted]', $redactor->redactText('josé@ejemplo.es and jane@localhost'));
        self::assertSame('at 2026-11-01 10:00:00 INV-2026-0105', $redactor->redactText('at 2026-11-01 10:00:00 INV-2026-0105'));
    }

    /** L3: wider default keys; short keys only as the whole name. */
    #[Test]
    public function defaultKeysCoverCredentialsAndPersonalData(): void
    {
        $redactor = new KeyPatternRedactor();
        foreach (['pwd', 'userPass', 'otp', 'totpSecret', 'dsn', 'DATABASE_URL', 'authHeader', 'jwt', 'accessKey', 'username', 'firstName', 'last_name', 'name', 'code', 'key', 'pin', 'zip', 'pan', 'ip', 'clientIp', 'customerDni', 'nif', 'nie', 'phoneNumber', 'postcode', 'recipient'] as $key) {
            self::assertTrue($redactor->isSensitiveKey($key), $key);
        }
        foreach (['transportName', 'statusCode', 'monkey', 'shipping', 'zipper', 'total', 'description', 'private'] as $key) {
            self::assertFalse($redactor->isSensitiveKey($key), $key);
        }
        self::assertTrue((new KeyPatternRedactor(['=Order-Ref']))->isSensitiveKey('order_ref'));
        self::assertFalse((new KeyPatternRedactor(['=Order-Ref']))->isSensitiveKey('orderRefs'));
    }

    /** M1: the chain applies the credential masks to failure messages / descriptions even with host redactors. */
    #[Test]
    public function theRedactorChainMasksDescriptions(): void
    {
        $description = (string) new class implements Stringable {
            public function __toString(): string
            {
                return 'Invoice for jane@example.test, DNI 12345678Z, token=REDACTED-TEST-TOKEN';
            }
        };

        self::assertSame('Invoice for [redacted], DNI [redacted], token=[redacted]', (new Redactor())->redactText($description));
    }

    /** L2: one node budget for the whole graph, objects shown once, byte cap on the JSON. */
    #[Test]
    public function theNormaliserHasAGlobalBudget(): void
    {
        $shared = new DemoMessage('shared');
        $wide   = [];
        for ($i = 0; $i < 40; ++$i) {
            $wide[] = ['a' => $shared, 'b' => [1, 2, 3, 4, 5]];
        }
        $data = (new PayloadNormalizer(6, 50, 500, 30))->normalize(['items' => $wide]);
        self::assertIsArray($data['items']);
        self::assertIsArray($data['items'][0]);
        self::assertIsArray($data['items'][1]);
        self::assertSame([PayloadNormalizer::CLASS_KEY => DemoMessage::class, 'orderId' => 'shared', 'email' => 'jane.doe@example.test', 'note' => 'Call +34 600 111 222 tomorrow', 'apiToken' => 'REDACTED-TEST-TOKEN'], $data['items'][0]['a']);
        self::assertSame('(ref: ' . DemoMessage::class . ')', $data['items'][1]['a'], 'already-seen objects anywhere in the graph');
        self::assertSame(PayloadNormalizer::BUDGET_MARKER, $data['items']['…']);
        self::assertCount(4, $data['items'], 'three items, then the budget marker');
        self::assertSame(['value' => PayloadNormalizer::BUDGET_MARKER], (new PayloadNormalizer(maxNodes: 0))->normalize('x'));

        $view = new PayloadView(new PayloadNormalizer(), new Redactor(), true, 1024);
        $json = $view->renderData(['big' => str_repeat('é', 2000)]);
        self::assertStringEndsWith(PayloadView::TRUNCATED_MARKER, $json);
        self::assertLessThanOrEqual(1024 + 20, strlen($json));
        self::assertSame('[]', (new PayloadView(new PayloadNormalizer(), new Redactor()))->renderData([]));
    }

    /** L2: property hooks and lazy initialisers never run (PHP 8.4+). */
    #[Test]
    public function theNormaliserRunsNoHooksNorLazyInitialisers(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks and lazy objects need PHP 8.4.');
        }
        // Defined at runtime: the hook and lazy-object syntax does not parse / analyse on PHP 8.2.
        if (!class_exists('MmkSecAuditHookProbe', false)) {
            eval('final class MmkSecAuditHookProbe { public static int $calls = 0; '
                . 'public string $backed = \'raw\' { get { ++self::$calls; return strtoupper($this->backed); } } '
                . 'public string $virtual { get { ++self::$calls; return \'virtual\'; } } '
                . 'public ?object $child = null; } '
                . 'final class MmkSecAuditLazyProbe { public static int $initialised = 0; public int $n = 1; }');
        }
        $object = eval('return new MmkSecAuditHookProbe();');
        $ghost  = eval('return (new ReflectionClass(MmkSecAuditLazyProbe::class))->newLazyGhost(static function (): void { ++MmkSecAuditLazyProbe::$initialised; });');
        self::assertIsObject($object);
        self::assertIsObject($ghost);
        (new ReflectionProperty($object, 'child'))->setValue($object, $ghost);
        $data = (new PayloadNormalizer())->normalize($object);

        self::assertSame(0, (new ReflectionProperty($object::class, 'calls'))->getValue(), 'no get hook ran');
        self::assertSame('raw', $data['backed']);
        self::assertArrayNotHasKey('virtual', $data);
        self::assertSame('(lazy: MmkSecAuditLazyProbe)', $data['child']);
        self::assertSame(0, (new ReflectionProperty($ghost::class, 'initialised'))->getValue(), 'the lazy initialiser did not run');
    }

    /** L1: one token id and one form name per action and target. */
    #[Test]
    public function csrfTokenIdsAreScopedToActionAndTarget(): void
    {
        $ids = [
            ActionForms::tokenId('retry', ['name' => 'failed', 'id' => '1']),
            ActionForms::tokenId('retry', ['name' => 'failed', 'id' => '2']),
            ActionForms::tokenId('remove', ['name' => 'failed', 'id' => '1']),
            ActionForms::tokenId('remove_all', ['name' => 'failed']),
            ActionForms::tokenId('remove_all', ['name' => 'other']),
        ];
        self::assertSame('nowo_mmk.retry.failed.1', $ids[0]);
        self::assertSame('nowo_mmk.remove_all.failed.all', $ids[3]);
        self::assertCount(5, array_unique($ids));
        self::assertNotSame(ActionForms::formName('retry', ['name' => 'failed', 'id' => '1']), ActionForms::formName('retry', ['name' => 'failed', 'id' => '2']));
    }

    /** I2: Symfony's failure transports are used when `failure_transports` is empty; the heuristic is word-based. */
    #[Test]
    public function failureTransportsAreDerivedFromMessenger(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(FailedMessageRetrier::class, new Definition(FailedMessageRetrier::class, [null, null, []]));
        $container->setDefinition('messenger.transport.dead', (new Definition())->addTag('messenger.receiver', ['alias' => 'dead', 'is_failure_transport' => true]));
        $container->setDefinition('messenger.transport.dead_copy', (new Definition())->addTag('messenger.receiver', ['alias' => 'dead', 'is_failure_transport' => true]));
        $container->setDefinition('messenger.transport.mail_failover', (new Definition())->addTag('messenger.receiver', ['alias' => 'mail_failover', 'is_failure_transport' => false]));
        $container->setDefinition('messenger.transport.untagged', (new Definition())->addTag('messenger.receiver', ['is_failure_transport' => true]));
        (new MessengerIntegrationPass())->process($container);
        self::assertSame(['dead'], $container->getDefinition(FailedMessageRetrier::class)->getArgument(2));

        $configured = new ContainerBuilder();
        $configured->setDefinition(FailedMessageRetrier::class, new Definition(FailedMessageRetrier::class, [null, null, ['parked']]));
        (new MessengerIntegrationPass())->process($configured);
        self::assertSame(['parked'], $configured->getDefinition(FailedMessageRetrier::class)->getArgument(2));
        (new MessengerIntegrationPass())->process(new ContainerBuilder());

        $retrier = new FailedMessageRetrier(Monitor::transports([]), new RecordingBus());
        foreach (['failed', 'async_failed', 'Failure-queue', 'failures', 'messages.fail'] as $name) {
            self::assertTrue($retrier->isFailureTransport($name), $name);
        }
        foreach (['mail_failover', 'async', 'failsafe', 'nofail'] as $name) {
            self::assertFalse($retrier->isFailureTransport($name), $name);
        }
    }

    /** I3: retry all skips duplicate-risk messages. */
    #[Test]
    public function retryAllSkipsDuplicateRiskMessages(): void
    {
        $failed  = new MemoryTransport();
        $async   = new MemoryTransport();
        $bus     = new RecordingBus();
        $retrier = new FailedMessageRetrier(Monitor::transports(['failed' => $failed, 'async' => $async]), $bus, ['failed'], null, [DemoMessage::class]);
        $failed->send(new Envelope(new DemoMessage(), [new SentToFailureTransportStamp('async')]));
        $failed->send(new Envelope(new OptionalMessage(), [new SentToFailureTransportStamp('async')]));
        $info = Monitor::transports(['failed' => $failed])->get('failed');

        $result = $retrier->retryAll($info, 10);
        self::assertSame(1, $result->done);
        self::assertSame(1, $result->skipped);
        self::assertCount(1, $bus->dispatched);
        self::assertSame(1, $failed->getMessageCount(), 'the duplicate-risk message stays parked');
        self::assertSame('done', $retrier->retry($info, '1')->status->value, 'a single, confirmed retry still works');
    }

    /** I6: a failed action logs the exception class and its redacted message, not the exception object. */
    #[Test]
    public function failedActionsLogARedactedMessage(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $failed             = new MemoryTransport();
        $failed->failReject = true;
        $retrier            = new FailedMessageRetrier(Monitor::transports(['failed' => $failed]), new RecordingBus(), ['failed'], logger: $logger);
        $failed->send(new Envelope(new DemoMessage()));

        try {
            $retrier->discard(Monitor::transports(['failed' => $failed])->get('failed'), '1');
            self::fail('expected the reject failure');
        } catch (RuntimeException) {
        }
        self::assertSame(RuntimeException::class, $logger->contexts[0]['exception_class']);
        self::assertSame('reject failed', $logger->contexts[0]['exception_message']);
        self::assertArrayNotHasKey('exception', $logger->contexts[0]);
    }

    /** I4: the Redis URL (password-bearing) is a sensitive parameter. */
    #[Test]
    public function theRedisDsnIsASensitiveParameter(): void
    {
        $parameter = (new ReflectionMethod(FailedTransportDsnEnvVarProcessor::class, 'messengerRedisDsn'))->getParameters()[0];
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
    }

    /** L4: the recipe does not redefine `security.access_control`. */
    #[Test]
    public function theRecipeShipsNoSecurityFile(): void
    {
        $recipe = dirname(__DIR__, 3) . '/.symfony/recipe/nowo-tech/messenger-monitor-kit-bundle/1.0';
        self::assertTrue(is_file($recipe . '/manifest.json'));
        self::assertFalse(is_file($recipe . '/config/packages/security_nowo_messenger_monitor_kit.yaml'));
        self::assertStringContainsString('access_control', (string) file_get_contents($recipe . '/post-install.txt'));
    }
}
