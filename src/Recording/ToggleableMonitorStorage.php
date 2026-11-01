<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Recording;

use Nowo\MessengerMonitorKitBundle\Payload\Redactor;
use Symfony\Component\Messenger\Envelope;
use Throwable;
use Zenstruck\Collection;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;

use function is_array;

/**
 * Decorates zenstruck's history storage (`zenstruck_messenger_monitor.history.storage`): skips writes while the
 * recording switch is off (no redeploy, no worker restart), skips `recording.optional_message_classes` unless their
 * switch is on, and minimises what is stored (handler results redacted, or dropped with `store_results: false`).
 * Reads, purges and deletes always delegate, so the UI keeps working while recording is off.
 */
final readonly class ToggleableMonitorStorage implements Storage
{
    /**
     * @param list<string> $optionalClasses message classes / parents recorded only with the optional switch
     * @param bool $storeResults false = save no handler results
     */
    public function __construct(
        private Storage $inner,
        private RecordingSwitch $switch,
        private Redactor $redactor,
        private array $optionalClasses = [],
        private bool $storeResults = true,
    ) {
    }

    /**
     * Saves the row unless recording (or optional recording) is off.
     */
    public function save(Envelope $envelope, Results $results, ?Throwable $exception = null): void
    {
        $settings = $this->switch->settings();
        if (!$settings->recording) {
            return;
        }
        if (!$settings->recordOptional && $this->isOptional($envelope->getMessage())) {
            return;
        }

        $this->inner->save($envelope, $this->minimise($results), $exception);
    }

    /**
     * Delegates.
     */
    public function find(mixed $id): ?ProcessedMessage
    {
        return $this->inner->find($id);
    }

    /**
     * Delegates.
     *
     * @return Collection<ProcessedMessage, int>
     */
    public function filter(Specification $specification): Collection
    {
        return $this->inner->filter($specification);
    }

    /**
     * Delegates.
     */
    public function purge(Specification $specification): int
    {
        return $this->inner->purge($specification);
    }

    /**
     * Delegates.
     */
    public function delete(mixed $id): void
    {
        $this->inner->delete($id);
    }

    /**
     * Delegates.
     */
    public function averageWaitTime(Specification $specification): ?int
    {
        return $this->inner->averageWaitTime($specification);
    }

    /**
     * Delegates.
     */
    public function averageHandlingTime(Specification $specification): ?int
    {
        return $this->inner->averageHandlingTime($specification);
    }

    /**
     * Delegates.
     */
    public function count(Specification $specification): int
    {
        return $this->inner->count($specification);
    }

    /**
     * Delegates.
     */
    public function perMessageTypeMetrics(Specification $specification): Collection
    {
        return $this->inner->perMessageTypeMetrics($specification);
    }

    /**
     * Delegates.
     */
    public function availableMessageTypes(Specification $specification): Collection
    {
        return $this->inner->availableMessageTypes($specification);
    }

    private function isOptional(object $message): bool
    {
        foreach ($this->optionalClasses as $class) {
            if ($message instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function minimise(Results $results): Results
    {
        if (!$this->storeResults) {
            return new Results([]);
        }

        $redacted = [];
        foreach ($results->jsonSerialize() ?? [] as $result) {
            if (isset($result['data']) && is_array($result['data'])) {
                $result['data'] = $this->redactor->redact($result['data']);
            }
            if (isset($result['message'])) {
                $result['message'] = (string) $this->redactor->redactText($result['message']);
            }
            $redacted[] = $result;
        }

        return new Results($redacted);
    }
}
