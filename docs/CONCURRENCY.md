# Concurrency (REQ-RUNTIME-003)

How the bundle stays correct when the same data is written at the same time: two admins, a worker and an admin, a
double submit, a redelivered message. Applies from **1.3.0**.

## Table of contents

- [Write paths](#write-paths)
- [Conflict answer and codes](#conflict-answer-and-codes)
- [Rules](#rules)
- [Host options](#host-options)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Write paths

The bundle never writes through an EntityManager: every write is one DBAL statement or one transport call. It never
flushes, clears or resets the host's EntityManager (`OwnHistoryManagerSubscriber` clears only the manager set in
`doctrine.entity_manager`, when there is one).

| Path | Transaction | Control | Conflict answer |
| --- | --- | --- | --- |
| History row of a handled / failed message (worker, `DbalHistoryWriter::save`) | One `INSERT`, auto-committed; inside a SAVEPOINT when the worker's connection has a transaction open (the row then follows that transaction, and a failed insert never breaks it) | Append-only: no read-modify-write; the id comes from the database (identity, or the sequence for a pre-insert generator) | Not HTTP: the failure is rethrown to Messenger (rule 8) |
| History row delete (`Storage::delete`) | One `DELETE` by id | Idempotent | Rethrown |
| Manual purge (`POST /purge`) | `SELECT` ids + `DELETE … IN (…)` per keyset batch, each auto-committed, at most `purge.max_batches_per_request` batches | Keyset on the primary key, bounded batches (rule 9); idempotent: running it again deletes what is left | First batch: `CONFLICT_001`; a later batch: `CONFLICT_002` |
| Automatic purge and `nowo:messenger-monitor:purge` (CLI, scheduler) | Same keyset batches, no batch cap | Same | Not HTTP: the command fails; the committed batches stay deleted |
| Retry one failed message (`POST …/retry`) | No common transaction (the target transport may be another connection or a broker): send to the original transport, then reject (`DELETE`) from the failure transport | Per-message lock (`transport + id`) and a second lookup inside the lock: two admins or a double submit never send it twice, and a retry never runs on a message another admin is removing | Before the send: `CONFLICT_001`; the send done but the reject blocked: `CONFLICT_002` |
| Remove one failed message (`POST …/remove`) | One reject (`DELETE`) | Same lock and lookup | `CONFLICT_001` |
| Retry all / remove all (`POST …/retry-all`, `…/remove-all`) | One message after the other, at most `retry.batch_limit` | Same lock and lookup per message; duplicate-risk classes are skipped by "retry all" | First message: as one message; a later one: `CONFLICT_002` |
| Settings switches (`POST /settings`) | One save in the store: a PSR-6 cache item (`cache`), the InstanceSettings section (`instance_settings`, that bundle's own writes and verdict), nothing (`config`, read-only) | Whole-object, last writer wins | Not a database lock: none (see residual risks) |

## Conflict answer and codes

A lock conflict on a bundle route (deadlock, lock wait timeout, serialization failure: MySQL 1213 / 1205, MariaDB
1020, PostgreSQL 40001 / 40P01 / 55P03, anywhere in the exception chain) answers through
`MessengerMonitorConflictListener`:

| Code | When | Status | Headers |
| --- | --- | --- | --- |
| `CONFLICT_001` | Nothing was committed: retrying is safe | 409 (or 503) | `X-Conflict-Code: CONFLICT_001`, `Retry-After` |
| `CONFLICT_002` | Part of the request was committed: read again, do not repeat | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` |

`CONFLICT_002` is chosen when:

- the bundle knows a step already committed: it throws a committed
  `Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict` (`PartialCommit`): a retry whose message was already sent
  back, a batch after its first message, a purge after its first batch;
- anything was committed earlier in the same request (transaction-doctrine's request commit mark, when
  NowoTransactionDoctrineBundle is registered with `commit_detection`);
- a Messenger failure shows that a handler already ran: a `HandlerFailedException` carrying a `HandledStamp`, or any
  `DelayedMessageHandlingException`.

The body is the platform answer: JSON `{"error", "code", "message"}` for JSON / XHR requests, else the "try again"
page (with NowoTransactionDoctrineBundle registered: its template and translations; else a plain English page). With
`conflict_response.error_page: true` your error page renders it, and the status and headers are the same.

Not conflicts, never mapped: unique violations and lost connections. They stay real errors (500). The bundle has no
"only one" rule enforced by a unique key, so there is no unique race to answer as a domain result. Host routes are
never answered: only the bundle's controllers.

## Rules

| Rule | How |
| --- | --- |
| 1. One transaction per write unit | Each write is one statement. The actions that take several steps (retry, batches, purge) cannot share one transaction (the target transport may be another connection or a broker); a conflict after a committed step answers `CONFLICT_002`. |
| 2. Never touch the host EntityManager | No flush, clear or reset of it in `src/`; the own manager only, when configured. |
| 3. Nested transactions repair themselves | The history insert / delete inside a host transaction runs in a SAVEPOINT, rolled back on failure; the original error is rethrown. |
| 4. Concurrency control | Per-message lock + second lookup for retry / remove; keyset batches for purge; append-only history. |
| 5. Exact keys | Transport names and message ids compare strictly (`in_array(…, true)`, the lock key hashes the exact bytes). |
| 6. Conflict answer | Above. |
| 7. Safe retries | A retried message keeps its handled stamps, so handlers that already succeeded do not run again. A redelivered message gets one history row per handling, by design. |
| 8. No silent swallowing | Database failures are rethrown everywhere. Only the automatic purge's "last run" cache write is logged and swallowed (not a database write). |
| 9. Bounded work under locks | The bundle holds no database row lock. The per-message lock is an application lock with a TTL (`retry.lock_ttl`) around one send and one reject; purges are bounded batches. |

## Host options

```yaml
nowo_messenger_monitor_kit:
    retry:
        lock_ttl: 30                 # seconds a retry / removal of one message is locked
        lock_factory: lock.factory   # LockFactory service; several servers: a shared store
    conflict_response:
        status: null                 # 409 | 503; null = transaction-doctrine's setting, else 409
        error_page: false            # true = your error page renders the conflict
```

- `retry.lock_factory`: the default `lock.factory` comes from `framework.lock` (on by default with symfony/lock; the
  default store is local: flock or semaphore). When it does not exist, the bundle uses a local flock store. With
  several servers, define a factory on a shared store and name it here:

  ```yaml
  framework:
      lock:
          messenger_monitor: '%env(LOCK_DSN)%'   # redis://…, postgresql+advisory://…, a DBAL DSN
  nowo_messenger_monitor_kit:
      retry:
          lock_factory: lock.messenger_monitor.factory
  ```

- `conflict_response.status`: keep 409 (platform default) or choose 503. With NowoTransactionDoctrineBundle registered,
  `null` follows `nowo_transaction_doctrine.conflict_response.status`.
- Cross-origin API clients read the code from `X-Conflict-Code`: list it in `Access-Control-Expose-Headers` if your
  CORS layer serves the admin UI to another origin.

## Tests and CI

- `tests/Integration/Persistence/FailedMessageRaceTest.php`: two real connections on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16, Doctrine transports and a DBAL lock store per connection. Retry versus remove and retry versus retry
  (the second admin gets "being handled", the message is sent once), remove then retry (nothing found), the same race
  without a lock (sent twice: why the lock is always on), a removal blocked by a row lock (`CONFLICT_001`, connection
  still usable), a retry whose reject is blocked (`CONFLICT_002`), and a purge blocked before / after its first batch.
  Lock waits are capped at one second (`innodb_lock_wait_timeout`, `lock_timeout`).
- `tests/Integration/Persistence/DbalHistoryWriterTest.php`: the history writer on SQLite, MySQL, MariaDB and
  PostgreSQL (host transaction, savepoint, pre-insert id generator).
- `tests/Integration/Functional/ConcurrencyConflictTest.php` and `tests/Unit/EventListener/MessengerMonitorConflictListenerTest.php`:
  409 + codes end to end, 503 and error-page modes, Messenger 002 cases, unique violations and lost connections not
  mapped, host routes ignored.
- CI job **Databases (MySQL 8.4, MariaDB 11.4, PostgreSQL 16, DBAL 3 / 4)**, a required check on `main`.

## Residual risks

- **A retry whose reject fails.** The message is back on its transport and still parked on the failure transport.
  The answer is `CONFLICT_002` ("read again"); retrying it again from the list would send it a second time. Handlers
  that already succeeded are skipped (handled stamps), but the failed handler runs again.
- **Local lock store with several servers.** The default store serialises one host only. Use a shared store (above).
- **Lock TTL.** If one send takes longer than `retry.lock_ttl` (a broker that hangs), the lock expires and a second
  admin could act on the same message. Raise the TTL for slow brokers.
- **Partial batch audit.** When a batch stops on a conflict after some messages, the audit event of the batch is not
  dispatched; the per-message info log lines remain.
- **Settings switches.** Two admins saving the switches at the same moment: the last save wins (whole object, no
  version). Admin-only, no data loss beyond the overwritten switches.
