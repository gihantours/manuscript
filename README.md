# Manuscript Submission Service

A Laravel 13 + React 19 service that accepts manuscript submissions, returns a
submission ID immediately, and processes each manuscript asynchronously through
named stages: validation, text extraction, similarity checking, preview
building, author notification, and completion.

The implementation treats the external similarity and email services as
unreliable: every side effect is retried safely, every email uses a stable
idempotency key, and every state transition is persisted with timestamps and
attempt counts.

## Quick start (Docker)

Requirements: Docker Desktop with Compose v2. Nothing else is needed.

```bash
cd manuscript-service
docker compose up --build
```

The first build installs PHP extensions and frontend dependencies, so allow a
few minutes. Once the `app` container is healthy:

- Frontend: <http://localhost:5173>
- API: <http://localhost:8000>
- Mock similarity/email service: <http://localhost:9000>
- MySQL: `localhost:3306` (`manuscripts` / `manuscripts` / `secret`)
- Redis: `localhost:6379`

The `app` container runs migrations and seeds two users on first start.

| Role | Email | Password |
| --- | --- | --- |
| Author | `author@example.com` | `password` |
| Editor | `editor@example.com` | `password` |

### Load the provided sample data

```bash
docker compose exec app php artisan sample:import
```

This reads `sample-data/manuscripts.zip` and `sample-data/manifest.csv`, imports
valid rows, rejects invalid rows with a report, and never writes outside the
intended extraction directory. The report is also written to
`storage/logs/import-report.json`.

Expected result from the provided archive:

- 16 imported
- 4 rejected: empty file (`ms_013`), binary/non-UTF-8 file (`ms_014`), invalid
  email (`ms_019`), missing source file (`ms_020`)
- `../evil.txt` rejected as an unsafe archive entry (zip slip)

### Useful commands

```bash
docker compose ps
docker compose logs -f app horizon outbox
docker compose exec app php artisan sample:import
docker compose exec app php artisan horizon:status
docker compose exec app php artisan dlq:list
docker compose exec app php artisan outbox:dispatch
```

### Logs

The Docker services use `LOG_CHANNEL=docker`, a stack that writes every record
twice: JSON to the container's stderr and JSON to `storage/logs/laravel.log`
(bind-mounted to the host).

```bash
docker compose logs -f app horizon outbox       # container stderr
tail -f storage/logs/laravel.log                # JSON file on the host
```

Production stays stderr-only with `LOG_CHANNEL=json` so the platform can ship
container output; see `DEPLOYMENT.md`.

### Live code reload (Docker)

`docker-compose.override.yml` is loaded automatically by `docker compose` and
bind-mounts `app/`, `config/`, `database/`, `routes/`, `resources/`, `tests/`,
and `storage/logs/` into `app`, `horizon`, and `outbox`. Backend edits therefore
apply without rebuilding.

- API changes (`app/`, `routes/`, `config/`) are picked up on the next request by
  `php artisan serve`.
- `horizon` and `outbox` are long-running. After changing jobs or services, run
  `docker compose exec horizon php artisan horizon:terminate` and
  `docker compose restart outbox` (or `docker compose restart horizon outbox`).
- New migrations are applied when the `app` container restarts, or manually with
  `docker compose exec app php artisan migrate`.
- Changes to `composer.json`/`composer.lock`, `Dockerfile`, `bootstrap/app.php`,
  `public/index.php`, `artisan`, or `phpunit.xml` still require
  `docker compose build app horizon outbox`.
- Production ignores the override: `docker compose -f docker-compose.yml up -d`.

## Local development (without Docker)

Requirements: PHP 8.3+ with `pdo_sqlite`, `mbstring`, `fileinfo`, `zip`,
Composer, Node 20+, Redis (optional locally), and a running mock service.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php -S 127.0.0.1:9000 sample-data/mock_services.py  # or: python sample-data/mock_services.py
php artisan serve

cd frontend
npm install
npm run dev
```

The committed `.env` defaults to SQLite, `QUEUE_CONNECTION=sync`, and points
the external services at `http://127.0.0.1:9000`, so a single-process demo
works without Redis. For asynchronous processing locally, use MySQL + Redis and
run `php artisan horizon` and `php artisan outbox:work`.

## Architecture

```
React SPA (Vite)
      |
      | Bearer token (Sanctum)
      v
Laravel API ──> MySQL (manuscripts, stages, outbox, email deliveries, similarity cache)
      |
      | transaction: manuscript + stage rows + outbox event
      v
Transactional outbox (outbox_events)
      |
      | outbox:work (SKIP LOCKED claim, retries with backoff)
      v
Redis queues ──> Horizon supervisors
      |              ├── high / default: validation, extraction, preview, finalize
      |              ├── similarity: external similarity calls (global funnel = 4)
      |              └── notifications: idempotent email delivery
      v
Mock services (:9000)
```

### Processing stages

Each manuscript has one `manuscript_processing_stages` row per stage. Rows carry
`status` (`pending`, `running`, `retrying`, `completed`, `failed`), `attempts`,
`started_at`, `finished_at`, and `last_error`, which powers the API and UI
timeline.

1. `received` — created synchronously with the submission.
2. `validated` — re-checks size, UTF-8, binary/control characters, MIME type,
   and the stored SHA-256 hash.
3. `text_extracted` — normalizes line endings, strips a UTF-8 BOM, collapses
   spaces, stores extracted text and word count.
4. `similarity_checked` — calls the external service under a Redis concurrency
   funnel (`SIMILARITY_CONCURRENCY=4`), caches scores by content hash, and marks
   `needs_attention` for scores >= 70.
5. `preview_built` — stores the first 200 words.
6. `author_notified` — sends the confirmation email with a stable key
   `manuscript:{id}:received`.
7. `done` — completes the manuscript unless an editor already set an editorial
   status.

Editorial status changes (`accepted`, `rejected`, `revision_requested`) are
written synchronously and return immediately. The author email is queued as a
separate side effect with key
`manuscript:{id}:editor-status:{status_version}`.

### Queue design

| Queue | Work | Why |
| --- | --- | --- |
| `high` / `default` | Validation, extraction, preview, finalize | High-priority submissions are routed to `high`; normal work stays on `default`. |
| `similarity` | External similarity calls | Isolated so slow/erroring similarity calls cannot starve API-facing stages. |
| `notifications` | Email delivery | Isolated so provider retries do not delay processing. |
| Failed jobs | Horizon failed-job store | Visible failure surface; replay through `dlq:*`. |

Horizon uses three supervisors (`supervisor-default`, `supervisor-similarity`,
`supervisor-notifications`) with bounded processes and 120s/60s timeouts.

### Reliability choices

- **Transactional outbox.** Submission commits the manuscript, stage rows, and
  a `manuscript.submitted` outbox event in one transaction. If Redis or the
  queue is unavailable, the event remains durable and is published later.
- **Idempotent stage jobs.** Every job first checks whether its stage is
  already completed, so redelivery after a worker crash is a no-op.
- **Stable email idempotency keys.** `email_deliveries.idempotency_key` is
  unique. A retry after a provider `504` uses the same key, so the provider
  returns `duplicate_ignored` and the author receives exactly one email.
- **Bounded retries.** Jobs try 5 times with 5s/15s/45s/120s backoff. On final
  failure the stage is marked failed, the manuscript becomes `failed` with
  `needs_attention=true` and a `failure_reason`.
- **Rate-limit handling.** `429` responses release the job after the provider's
  `Retry-After` value. Like any release, that redelivery counts against the
  bounded attempt budget, so a persistent 429 ends in a visible failure instead
  of looping forever. Similarity concurrency overflow releases the job after 15
  seconds.
- **Visibility timeout.** `REDIS_QUEUE_RETRY_AFTER` is 180s, comfortably above
  the 120s stage/Horizon timeout, so a still-running job is not redelivered
  while it is in flight.
- **Replay.** `dlq:list` and `dlq:replay` requeue failed stages with an audit
  entry in `storage/logs/replay.log`; the outbox event is recreated, not
  mutated in place.
- **Safe untrusted filenames.** Storage keys are UUIDs. Original names are
  metadata only. ZIP entries are normalized and rejected on absolute paths,
  `..`, or symlinks.

## Queue decisions

We use **Redis queues with Laravel Horizon** because Laravel has first-class
Redis queue support, Horizon gives worker supervision and failed-job visibility
with almost no extra code, and Redis already provides the cache, sessions, and
the distributed concurrency funnel. MySQL remains the source of truth; Redis is
only transport. What runs in the request and what goes through the queue, and
why:

| Operation | Handling | Reason |
| --- | --- | --- |
| User logs in | Direct request | Authentication must be fast and the client needs the token immediately; there is nothing slow or retryable to defer. |
| Author uploads a manuscript | Direct request for auth, validation, durable file storage, and the manuscript + stage + outbox transaction; queue everything else | The API must return the ID right away. The durable commit is the boundary: once it succeeds the work is guaranteed to be published, even if Redis is down. |
| Editor opens the list of all manuscripts | Direct request | It is a bounded, paginated database read. Queuing it would only add latency and staleness. |
| Extracting text from a 50 MB file | Queue | CPU- and memory-heavy and potentially slow; blocking an HTTP worker would hurt every other request. |
| Calling the similarity service | Queue, on the dedicated `similarity` queue | Slow (1–3 s), flaky (503/429), and globally capped at four requests, so it must be isolated and rate-limited rather than inline. |
| Sending the "we received your manuscript" email | Queue, on the `notifications` queue | External calls fail and need retries; a stable idempotency key makes retries safe. It must not delay the submission response. |
| Editor accepts a manuscript, author must see the new status immediately | Direct database update; email queued | The status change is committed and returned synchronously, so the editor sees it at once. The email is a retryable side effect. |
| Updating a search index after a status change | Queue | Indexing is secondary, can lag briefly, and should be retried without failing the editorial action. |
| Generating a nightly summary report | Scheduled job that enqueues work | It runs outside any request, may be expensive, and can be retried or replayed without a user waiting. |
| Deleting an author account and all their data | Queue an asynchronous orchestration after the request is accepted | Deletion spans many rows and files; treating it as a tracked background job gives progress, retries, and an audit trail. |

### Delivery guarantee

The queue is **at-least-once**. Exactly-once execution is not assumed or
claimed. A job may run again after a worker crash, a lost acknowledgement, or a
visibility timeout. The code copes by making every stage idempotent: durable
stage rows with a unique `(manuscript_id, stage_name)` constraint, transactions
with conditional state changes, `firstOrCreate` email-delivery rows with a
unique idempotency key, and a completed-stage check at the start of every job.
Re-running a completed stage is a no-op.

### If the similarity service is down for an hour

Calls fail with bounded exponential backoff (5s/15s/45s/120s, then stop), and
`429` responses are released using the provider's `Retry-After` instead of
burning an attempt. After the retry limit the stage is marked `failed` with the
error, the manuscript becomes `failed` with `needs_attention=true`, and the work
is visible for `dlq:list` / `dlq:replay`. The dedicated `similarity` queue keeps
the outage from consuming `default` capacity, and sustained failures should
alert.

### If 1,000 manuscripts arrive in one minute

The API does only bounded work per request: validate, stream the file to
storage, and commit the manuscript, stage rows, and outbox event in one
transaction. That caps the synchronous cost and lets Redis absorb the backlog.
The outbox publisher drains events in batches, Horizon scales on queue depth and
oldest-message age, and the four-permit Redis funnel keeps similarity calls
within the provider limit regardless of how many workers are running. Upload and
submission endpoints are rate-limited, and backpressure shows up as queue wait
time rather than dropped work.

### If a worker crashes halfway through a job

The job has not been acknowledged, so the queue makes it visible again after
its reservation/visibility timeout. Because database side effects and the
advance-to-next-stage outbox insert happen in the same transaction, a crash
either leaves the stage pending/retrying (safe to retry) or fully committed
(idempotent no-op on redelivery). Nothing is silently lost or permanently stuck;
repeated failures land in the failed-job store for replay.

### What would make us pick a different queue technology

- **Amazon SQS** if managed durability, dead-letter queues, visibility timeouts,
  and elastic scaling matter more than Redis features — the natural production
  choice for this workload.
- **RabbitMQ** if we needed broker-level routing, exchanges, and complex
  per-consumer delivery semantics.
- **Kafka** if we needed durable, replayable event streaming across many
  independent consumers rather than simple background jobs.

## Duplicate manuscript policy

If the same text is submitted more than once:

- Every submission gets its own manuscript record and is processed
  independently; a new submission is never silently discarded.
- The SHA-256 of the uploaded bytes (`file_hash`) identifies exact duplicates,
  and the normalized extracted-text hash keys `similarity_caches` so a completed
  similarity score can be reused instead of calling the provider again.
- A similarity cache hit still advances that manuscript's own pipeline and
  records its own stages.
- Authors never see another author's manuscript, metadata, or submission
  details; duplicate detection does not leak across users.

## Priority handling

High-priority manuscripts are routed to a separate `high` queue drained ahead of
`default`, with a reserved share of `supervisor-default` processes so normal work
cannot be starved. This is worth building because it is cheap (one queue plus
Horizon routing) and does not change the global four-request similarity limit:
priority changes ordering, not throughput. A flood of high-priority work still
cannot exceed the provider cap.

## API

All protected routes require `Authorization: Bearer <token>` and
`Accept: application/json`.

| Method | Path | Role | Purpose |
| --- | --- | --- | --- |
| `POST` | `/api/login` | public | `{email,password}` → `{token,user}` |
| `GET` | `/api/user` | any | Current user |
| `POST` | `/api/logout` | any | Revoke current token |
| `GET` | `/api/manuscripts` | author | Own manuscripts, paginated, filters `status`, `priority`, `needs_attention` |
| `POST` | `/api/manuscripts` | author | Multipart submission: `title`, `author_email`, `priority` (`normal`/`high`), `file` (`.txt`, <= 10 MB) |
| `GET` | `/api/manuscripts/{public_id}` | owner or editor | Detail plus stage timeline |
| `GET` | `/api/editor/manuscripts` | editor | All manuscripts, same filters |
| `PATCH` | `/api/editor/manuscripts/{public_id}/status` | editor | `{status: accepted\|rejected\|revision_requested}` |

Machine-readable reference: [openapi.yaml](openapi.yaml) (OpenAPI 3.1), covering
every endpoint, auth, request/response schemas, and error shapes.

Submission returns `202` with `{id, status}`. `id` is the public ID (`m_1`),
not the database key. Responses include `X-Request-ID`. Logs are JSON: every API
line carries `request_id`, and manuscript-scoped API and worker lines carry
`manuscript_id` (workers also add `stage` and `attempt`).

### Why 10 MB?

10 MB keeps the local demo honest: the API reads and validates the whole upload
in the request, so a tight cap bounds synchronous memory and latency, and the
slower extraction work moves to the queue. The provided 7.4 MB sample fits. In
production the requirement is 50 MB files, which should use direct-to-S3 or
multipart uploads so the API never buffers the file, with the same validation
run asynchronously.

Example:

```bash
TOKEN=$(curl -s http://localhost:8000/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"author@example.com","password":"password"}' | jq -r .token)

curl -s -X POST http://localhost:8000/api/manuscripts \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' \
  -F 'title=Queue Reliability' \
  -F 'author_email=author@example.com' \
  -F 'priority=high' \
  -F 'file=@sample.txt;type=text/plain'
```

## Data model

- `users`: `id`, `name`, `email` (unique), `password`, `role` (`author`/`editor`).
- `manuscripts`: `public_id` (unique `m_{id}`), `user_id`, `title`,
  `author_email`, `original_filename`, `file_path`, `file_hash`, extracted text
  path/hash, `priority`, `status`, `status_version`, `needs_attention`,
  `similarity_score`, `word_count`, `preview`, `failure_reason`, timestamps.
- `manuscript_processing_stages`: unique `(manuscript_id, stage_name)`, status,
  attempts, timestamps, `last_error`.
- `email_deliveries`: unique `idempotency_key`, event type, recipient, subject,
  status, attempts, `sent_at`, `last_error`.
- `outbox_events`: event type, aggregate ID, JSON payload, `available_at`,
  `attempts`, `published_at`, `last_error`.
- `similarity_caches`: content hash primary key, score, timestamps.

## Sample data findings

The archive hides several edge cases:

| Entry | Finding | Behaviour |
| --- | --- | --- |
| `ms_013.txt` | Zero bytes | Rejected during validation |
| `ms_014.txt` | PNG disguised as `.txt` | Rejected (invalid UTF-8 / binary) |
| `ms_015.txt` | ~7.4 MB | Accepted; async processing keeps upload response fast |
| `ms_018 final (v2).txt` | Spaces and parentheses | Accepted; UUID storage key, original name kept as metadata |
| `ms_019.txt` | `not-an-email` | Rejected with row-level error |
| `ms_020.txt` | Missing from archive | Rejected with "source file is missing" |
| `../evil.txt` | ZIP-slip entry | Rejected; nothing written outside the extraction directory |
| `MS_010` | Quoted comma in title | Parsed with `fgetcsv`, not `explode(',')` |
| `MS_004`, `MS_009`, `MS_017` | High priority | Routed to the `high` queue |

Of the 20 manifest rows, 6 have valid distinct emails plus one invalid email.

## Tests

```bash
php artisan test --compact
```

Current result: **26 tests, 26 passed, 65 assertions**. Coverage includes:

- submission validation, invalid email, unsupported extension, oversized file
- author/editor authorization boundaries
- email idempotency, including simulated `504`-after-send and job re-run
- transient vs permanent similarity failures and retry behaviour
- `429` releases honoring `Retry-After`, and a bounded retry/backoff policy
- Redis visibility timeout staying above every job and Horizon timeout
- API submission logs including the `manuscript_id` and outcome
- outbox claiming/publishing and publish-failure handling
- sample-data import, including zip-slip rejection
- Redis similarity concurrency limit (skips when Redis is unavailable)

Pint formatting:

```bash
vendor/bin/pint --dirty --format agent
```

Frontend build:

```bash
cd frontend && npm run build
```

## Chaos and load testing

```bash
# Kill Horizon mid-flight and recover
powershell -File scripts/chaos.ps1 worker 15
# Stop the mock dependency and watch background retries
powershell -File scripts/chaos.ps1 similarity 20

# 50 submissions at concurrency 10
php scripts/load-test.php http://localhost:8000 50 10
```

`GET http://localhost:9000/notify/log` shows `emails_sent` and every delivery
entry, including `duplicate_ignored` results for repeated idempotency keys;
`POST /admin/reset` clears the mock state.

## Extra credit implemented

- Transactional outbox with `FOR UPDATE SKIP LOCKED` claiming and a dedicated
  publisher process.
- DLQ inspection and replay commands (`dlq:list`, `dlq:replay`) with an audit
  log.
- Isolated priority routing (`high`) and dedicated similarity/notification
  queues with separate Horizon supervisors.
- Similarity result cache keyed by normalized content hash.
- Editor status versioning so repeated status changes produce distinct
  idempotency keys.
- Sample-data importer with a JSON report and explicit archive-safety checks.
- Chaos and load-test scripts, plus request/job correlation IDs in logs.
- React UI with submission form, author list, editor list, filters, status
  timeline, and polling until terminal state.


## Trade-offs and known gaps

- Local uploads use the filesystem; production should use direct-to-object-store
  uploads (pre-signed URLs, multipart) and a virus scanner.
- The similarity service receives the extracted text inline. Production should
  pass a signed object URL or content hash to avoid large payloads.
- Duplicate manuscript text is allowed and each submission is processed
  independently; this is the documented duplicate policy.
- Horizon's UI is intentionally not exposed in non-local environments. Use
  `php artisan horizon:status`, metrics, and structured logs instead.
- The outbox publisher is safe to run with one worker. If horizontally scaled,
  redundant publishes are tolerated because stage jobs and emails are
  idempotent.

See [DEPLOYMENT.md](DEPLOYMENT.md) for the production plan.
