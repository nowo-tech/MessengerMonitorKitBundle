<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Payload;

use ArrayObject;
use Closure;
use DateTimeImmutable;
use Nowo\MessengerMonitorKitBundle\Payload\KeyPatternRedactor;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadNormalizer;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadRedactorInterface;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadView;
use Nowo\MessengerMonitorKitBundle\Payload\Redactor;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function is_string;

final class RedactionTest extends TestCase
{
    #[Test]
    public function keysArePatternMatchedAndShortKeysExactly(): void
    {
        $redactor = new KeyPatternRedactor();
        $data     = $redactor->redact([
            'apiKey'    => 'k',
            'X-Api-Key' => 'k',
            'to'        => ['a@b.test', 'c@d.test'],
            'total'     => 12,
            'nested'    => ['user_email' => 'x', 'note' => 'mail jane@x.test or call +34 600 111 222', 'count' => 3],
            0           => 'plain',
            'flag'      => true,
        ]);

        self::assertSame('[redacted]', $data['apiKey']);
        self::assertSame('[redacted]', $data['X-Api-Key']);
        self::assertSame(['[redacted]', '[redacted]'], $data['to']);
        self::assertSame(12, $data['total']);
        self::assertSame(['user_email' => '[redacted]', 'note' => 'mail [redacted] or call [redacted]', 'count' => 3], $data['nested']);
        self::assertSame('plain', $data[0]);
        self::assertTrue($data['flag']);
        self::assertSame('INV-2026-0105 for [redacted]', $redactor->redactText('INV-2026-0105 for 600 111 222'));
        self::assertFalse($redactor->isSensitiveKey(5));
    }

    #[Test]
    public function patternsCanBeSwitchedOffAndKeysReplaced(): void
    {
        $redactor = new KeyPatternRedactor(['', 'secret'], '***', false, false);
        self::assertSame(['email' => 'a@b.test', 'secret_value' => '***', 'n' => '600111222333'], $redactor->redact(['email' => 'a@b.test', 'secret_value' => 's', 'n' => '600111222333']));
        self::assertSame('a@b.test 600111222333', $redactor->redactText('a@b.test 600111222333'));
    }

    #[Test]
    public function theChainRunsHostRedactorsAfterTheDefaultOne(): void
    {
        $host = new class implements PayloadRedactorInterface {
            public function redact(array $data, ?string $messageClass = null): array
            {
                foreach ($data as $key => $value) {
                    if (is_string($value)) {
                        $data[$key] = str_replace('order', 'ORDER', $value);
                    }
                }

                return $data;
            }
        };
        $chain = new Redactor(new KeyPatternRedactor(), [$host]);

        self::assertSame(['id' => 'ORDER-1', 'email' => '[redacted]'], $chain->redact(['id' => 'order-1', 'email' => 'x']));
        self::assertSame('ORDER for [redacted]', $chain->redactText('order for a@b.test'));
        self::assertNull($chain->redactText(null));
        self::assertSame('', $chain->redactText(''));

        $dropping = new Redactor(new KeyPatternRedactor(), [new class implements PayloadRedactorInterface {
            public function redact(array $data, ?string $messageClass = null): array
            {
                return [];
            }
        }]);
        self::assertSame('kept', $dropping->redactText('kept'));
    }

    #[Test]
    public function theNormalizerBoundsDepthItemsAndStrings(): void
    {
        $normalizer = new PayloadNormalizer(3, 10, 5);
        $root       = new class {
            public const SKIPPED = 'constants are not properties';

            public string $text = 'abcdefgh';

            public string $uninitialised;

            public mixed $self = null;

            /** @var list<int> */
            public array $list = [1, 2, 3];

            public Closure $closure;

            public mixed $resource = null;

            public function __construct()
            {
                $this->closure = static fn (): int => 1;
            }
        };
        $root->self     = $root;
        $root->resource = fopen('php://memory', 'r');

        $data = $normalizer->normalize($root);
        self::assertSame('abcde…', $data['text']);
        self::assertArrayNotHasKey('uninitialised', $data);
        self::assertArrayNotHasKey('SKIPPED', $data);
        self::assertStringStartsWith('(recursion: ', (string) $data['self']);
        self::assertSame([1, 2, 3], $data['list']);
        self::assertSame([1, 2, '…' => '(1 more items)'], (new PayloadNormalizer(3, 2))->normalize([1, 2, 3]));
        self::assertSame('(closure)', $data['closure']);
        self::assertSame('(resource)', $data['resource']);

        $deep = (new PayloadNormalizer(1))->normalize(['a' => ['b' => 1], 'o' => new ArrayObject()]);
        self::assertSame('(array of 1 items)', $deep['a']);
        self::assertSame('(ArrayObject)', $deep['o']);

        self::assertSame(['value' => 'x'], (new PayloadNormalizer())->normalize('x'));
        self::assertSame(['value' => '(binary data)'], (new PayloadNormalizer())->normalize("\xff\xfe"));
        $scalars = (new PayloadNormalizer())->normalize([null, true, 1, 1.5, Suit::Hearts, Plain::One, new DateTimeImmutable('2026-01-02T03:04:05+00:00')]);
        self::assertSame([null, true, 1, 1.5, 'H', 'One', '2026-01-02T03:04:05+00:00'], $scalars);
    }

    #[Test]
    public function theViewRendersRedactedEscapableJson(): void
    {
        $view = new PayloadView(new PayloadNormalizer(), new Redactor());
        $json = (string) $view->render(new DemoMessage());
        self::assertTrue($view->isEnabled());
        self::assertStringContainsString('"orderId": "order-42"', $json);
        self::assertStringNotContainsString('jane.doe@example.test', $json);
        self::assertStringNotContainsString('REDACTED-TEST-TOKEN', $json);
        self::assertStringContainsString('"x": "[redacted]"', $view->renderData(['x' => 'a@b.test']));

        $off = new PayloadView(new PayloadNormalizer(), new Redactor(), false);
        self::assertNull($off->render(new DemoMessage()));
        self::assertFalse($off->isEnabled());
    }
}

enum Suit: string
{
    case Hearts = 'H';
}

enum Plain
{
    case One;
}
