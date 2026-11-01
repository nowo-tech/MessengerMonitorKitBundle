<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Messenger;

use Closure;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

use function http_build_query;
use function parse_str;
use function preg_match;
use function str_starts_with;
use function trim;

/**
 * `%env(messenger_failed_dsn:MESSENGER_FAILED_TRANSPORT_DSN)%` — DSN of a listable failure transport.
 *
 * The failure transport must be listable (failed message pages, `messenger:failed:*`) and the AMQP transport is not.
 * When the named variable is empty the DSN is derived: a Redis / Doctrine queue DSN is reused as is (same database);
 * an AMQP queue falls back to the Redis URL (`transport_factories.failed_dsn_env.redis_env`), with its `/N` database
 * path turned into `?dbindex=N` (a path on a Messenger Redis DSN would be read as the stream name). Without a Redis
 * URL the queue DSN stays (stored, not listable).
 */
final readonly class FailedTransportDsnEnvVarProcessor implements EnvVarProcessorInterface
{
    /** Env var processor prefix. */
    public const PREFIX = 'messenger_failed_dsn';

    /**
     * @param string $queueEnv variable holding the queue DSN
     * @param string $redisEnv variable holding the Redis URL
     */
    public function __construct(
        private string $queueEnv = 'MESSENGER_TRANSPORT_DSN',
        private string $redisEnv = 'REDIS_URL',
    ) {
    }

    /**
     * Resolves the failure transport DSN.
     */
    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        $explicit = trim((string) $getEnv($name));
        if ($explicit !== '') {
            return $explicit;
        }

        $queue = (string) $getEnv($this->queueEnv);
        if (!str_starts_with($queue, 'amqp://') && !str_starts_with($queue, 'amqps://')) {
            return $queue;
        }

        try {
            $redis = trim((string) $getEnv($this->redisEnv));
        } catch (EnvNotFoundException) {
            $redis = '';
        }

        return $redis === '' ? $queue : self::messengerRedisDsn($redis);
    }

    /**
     * `redis://:pass@host:6379/1?timeout=2` → `redis://:pass@host:6379?timeout=2&dbindex=1`.
     */
    public static function messengerRedisDsn(#[SensitiveParameter] string $redisUrl): string
    {
        if (preg_match('~^(rediss?://[^/?#]*)(?:/(\d*))?/?(?:\?([^#]*))?$~i', $redisUrl, $m) !== 1) {
            return $redisUrl;
        }

        parse_str($m[3] ?? '', $query);
        if (isset($m[2]) && $m[2] !== '' && !isset($query['dbindex'])) {
            $query['dbindex'] = $m[2];
        }

        return $m[1] . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * `messenger_failed_dsn` returns a string.
     *
     * @return array<string, string>
     */
    public static function getProvidedTypes(): array
    {
        return [self::PREFIX => 'string'];
    }
}
