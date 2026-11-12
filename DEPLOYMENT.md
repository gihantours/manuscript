# Deployment Plan

This document covers the local Docker environment and a production topology for
the Manuscript Submission Service.

## Local environment

```bash
cd manuscript-service
docker compose up --build -d
docker compose exec app php artisan sample:import
```

Services and ports:

| Service | Port | Notes |
| --- | --- | --- |
| `frontend` | 5173 | Vite dev server |
| `app` | 8000 | Laravel API; runs migrations + seed on first start |
| `horizon` | internal | Queue workers for `high`, `default`, `similarity`, `notifications` |
| `outbox` | internal | Publishes committed outbox rows to Redis |
| `mock` | 9000 | Similarity + email simulator (`SEED` reproducible) |
| `mysql` | 3306 | MySQL 8.4, volume `mysql-data` |
| `redis` | 6379 | Queues, cache, sessions, funnel locks |

Health: `GET http://localhost:8000/up`, `GET http://localhost:9000/health`,
`mysqladmin ping`, `redis-cli ping`.

`docker-compose.override.yml` is a development-only file that bind-mounts backend
source into `app`, `horizon`, and `outbox` for live reload. It is never part of a
production deployment: run production with
`docker compose -f docker-compose.yml up -d` (or deploy the built image), so the
container filesystem stays immutable. Horizon workers still need a terminate or
restart to load changed job code, and composer/Dockerfile changes require a
rebuild.

Reset everything, including volumes:

```bash
docker compose down -v
docker compose up --build -d
```

Scale queue workers locally:

```bash
docker compose up -d --scale horizon=2
```

The outbox worker should remain single-instance locally. Multiple instances are
safe in production because duplicate publishes are idempotent.

## Environment variables

| Variable | Production guidance |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | 32-byte base64 key from a secret manager; never commit |
| `APP_URL` | Public HTTPS API origin |
| `FRONTEND_URL` | Public HTTPS SPA origin (CORS) |
| `LOG_CHANNEL` | `json` in production (stderr only); the local Docker override uses `docker`, which also writes JSON to `storage/logs/laravel.log` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` | Managed MySQL endpoint |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Injected from secret manager |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | Managed Redis with TLS |
| `QUEUE_CONNECTION` | `redis` |
| `REDIS_QUEUE_RETRY_AFTER` | Must exceed the longest job and Horizon timeout (set to `180` for the 120s stage timeout); otherwise a long-running job can be redelivered while still in flight |
| `CACHE_STORE` | `redis` |
| `SESSION_DRIVER` | `redis` (API uses Sanctum tokens; sessions only for Horizon) |
| `SIMILARITY_SERVICE_URL` | Internal HTTPS service URL |
| `NOTIFICATION_SERVICE_URL` | Internal HTTPS service URL |
| `SIMILARITY_CONCURRENCY` | `4` initially; tune against provider quota |
| `FILESYSTEM_DISK` | `local` in the demo; `s3` in production |

CORS is restricted by origin in production:

```php
'allowed_origins' => [env('FRONTEND_URL')],
'supports_credentials' => false,
```

The current repository ships permissive CORS for the local demo; change it
before exposing the API publicly.

## Production topology

```
                 TLS / WAF
                    |
             Load balancer
                    |
      ┌─────────────┴─────────────┐
      |                           |
 API instances (2+)          SPA static hosting/CDN
      |                           |
      ├── MySQL (managed, Multi-AZ, read replica for reporting)
      ├── Redis (managed, TLS, persistence, maxmemory policy noeviction for queues)
      ├── Object storage (uploads + extracted text, versioned, encrypted)
      └── Queue workers (Horizon, 2+ instances)
                 |
        Similarity service / Email provider
```

- Run the API in stateless containers behind a load balancer. Do not store
  uploads on the container filesystem; use object storage.
- Run Horizon workers separately from API containers. Keep at least two
  workers for availability, and cap `maxProcesses` so database and Redis
  connection pools stay healthy.
- Run the outbox publisher as its own supervised process. One instance is
  enough; alerts fire if the oldest unpublished event exceeds the SLO.
- Use a managed MySQL with automated backups, point-in-time recovery, and
  `innodb_flush_log_at_trx_commit=1`.
- Use managed Redis with persistence enabled and separate logical databases (or
  separate clusters) for queues, cache, sessions, and funnel locks.

## Delivery pipeline

1. **Build once.** Composer install with production flags, build the frontend
   with `npm ci && npm run build`, and publish the SPA to static hosting/CDN.
2. **Test gates.** `php artisan test --compact`, `vendor/bin/pint --test`,
   `npm run build`, plus dependency and container scanning.
3. **Database migration job.** Run `php artisan migrate --force` as a one-shot
   job before rolling API/worker containers. Migrations must be backward
   compatible for one release so old and new containers can coexist.
4. **Roll workers first, then API.** New workers can process old payloads; new
   API code can create new event types. Deploy in the order: workers, outbox,
   API.
5. **Smoke test.** Login, submit a small text file, poll until `completed`,
   verify `/notify/log` has one email, and verify `GET /up`.
6. **Rollback.** Keep the previous image tag. Roll back API and workers
   together. Do not roll back destructive migrations; use expand/contract
   migrations instead.

## Migrations and seeding

- Production runs `php artisan migrate --force` only. `db:seed` is for local
  development; create real users through a controlled admin process.
- Never auto-migrate from every API replica. Use a dedicated migration task.
- The `email_deliveries.idempotency_key` unique index and
  `manuscript_processing_stages (manuscript_id, stage_name)` unique index are
  required for correctness; verify them after migration.

## Scaling

| Symptom | Action |
| --- | --- |
| API latency rises, CPU high | Add API replicas; keep DB connection count bounded |
| Queue wait time grows for `default` | Add Horizon `supervisor-default` processes or instances |
| Similarity calls saturate the provider | Keep `SIMILARITY_CONCURRENCY=4`; add queue wait time, not more workers |
| Email provider rate-limits | Reduce notification supervisor processes; honor `Retry-After` |
| Outbox lag grows | Check `outbox:work`, Redis, and DB; add a second publisher only if duplicate-safe alerts are wired |
| MySQL CPU/IO high | Add read replica for editor lists, archive old stages, tune indexes |

The similarity funnel is global across workers because it uses Redis. Never
increase worker concurrency without keeping the funnel, or the provider's
four-request limit will be exceeded.

## When things go wrong

- **Similarity service down for an hour.** Jobs stay on the `similarity` queue
  and retry with bounded exponential backoff; `429` uses `Retry-After`. After
  five attempts the stage is marked `failed`, the manuscript becomes `failed`
  with `needs_attention=true`, and the job is visible in the failed store. The
  dedicated queue means `default`/`notifications` keep flowing. Alert on
  similarity failure rate and queue age; replay with `dlq:replay` once healthy.
- **A job that crashes the worker every time.** Laravel marks it failed after
  its bounded retries and stops reserving it; it does not keep re-crashing a
  worker. It stays in the failed-job store for inspection/replay, and Horizon
  alerts on repeated failures. A DLQ in production provides the same isolation.
- **Queue itself unavailable.** Submission still commits the manuscript, stages,
  and outbox event to MySQL and returns `202`. The outbox publisher retries
  publication with backoff; nothing is lost, and work resumes when Redis/SQS
  returns. Workers reconnect and continue; visibility timeouts redeliver any
  unacknowledged job.
- **Database away for a minute.** API writes fail loudly (no false `202`), and
  requests are retried by the client or edge. Workers fail their current job and
  retry after backoff; because stage side effects are transactional, a half-done
  stage is either not committed or safely re-executed. Alerts fire on DB
  availability and connection saturation.
- **Deploy or worker termination.** Horizon and `outbox:work` stop claiming new
  work on `SIGTERM`, in-flight jobs finish within the stop grace period or are
  released, and the 180s visibility timeout prevents premature redelivery.
  Duplicate execution is safe because every stage is idempotent.

## Cost and limits

At 10x volume (100,000 manuscripts/day, spikes of 20,000/hour), the first
constraints are:

1. **Similarity provider throughput.** Four concurrent calls capped globally
   means throughput is bounded by provider latency, not worker count. This is the
   first wall. Mitigate with the content-hash cache, provider capacity
   negotiation, or batch/async scoring if the vendor offers it.
2. **Database connections and write rate.** Each API replica and worker holds
   connections. Use a proxy (RDS Proxy), cap `maxProcesses`, batch stage updates,
   and archive old stage rows; add a read replica for editor lists.
3. **Extraction CPU/memory.** Large files tie up workers. Scale extraction
   workers independently and enforce the upload limit; 50 MB production uploads
   use direct-to-S3/multipart so the API does not hold the bytes.
4. **Storage and egress.** Object storage grows with originals plus extracted
   text. Use lifecycle policies, retention rules, and private buckets with
   signed downloads; watch S3 request and egress cost.
5. **Queue and log volume.** More jobs mean more Redis memory and log/metric
   spend. Keep payloads small (IDs, not text), trim Horizon metrics, and sample
   verbose logs.

Cost controls: raise the upload ceiling only with direct-to-object-store uploads,
cache similarity results aggressively, right-size workers on queue age, and use
spot/graviton capacity for stateless workers.

## Observability

Ship structured JSON logs to the platform (CloudWatch, Loki, Datadog, etc.).
Every request carries `request_id`; jobs carry `manuscript_id`, `stage`, and
`attempt`.

Dashboards and alerts:

- Submission rate, `202` rate, `4xx`/`5xx` rate, p95 API latency.
- Queue depth and oldest-job age per queue; Horizon wait-time thresholds.
- Stage failure rate and retry counts by stage.
- Outbox oldest unpublished age and publish failures.
- Similarity latency, `429` rate, `5xx` rate, cache hit rate.
- Email success/failure and duplicate-send indicator (`/notify/log` in demo;
  provider webhooks/metrics in production).
- MySQL connections, CPU, storage, slow queries; Redis memory, evictions,
  replication lag; worker restarts and OOM kills.

Suggested alerts:

1. Oldest queue message exceeds the agreed processing SLO for 10 minutes.
2. Outbox oldest unpublished event exceeds 60 seconds.
3. Similarity or email failure rate exceeds 10% for 5 minutes.
4. Any stage dead-letter count increases without a matching replay.
5. `/up` failing or API 5xx rate above 1%.

## Security

- Sanctum bearer tokens; revoke on logout. Use short token lifetimes and HTTPS
  only. Do not enable stateful SPA cookie auth unless CSRF protection is added.
- Validate upload size, extension, UTF-8, control characters, and MIME type on
  the server (never trust the browser).
- Store uploads outside the web root with UUID keys; serve downloads through
  signed URLs with authorization checks.
- Add malware scanning and archive-bomb limits before processing untrusted
  files in production.
- Keep secrets in a secret manager, rotate `APP_KEY` only with a re-encryption
  plan, and never log credentials or full request bodies.
- Rate-limit login and submission endpoints at the edge in addition to the
  application throttle.
- Patch base images and dependencies weekly; run `composer audit` and
  `npm audit` in CI.

## Backup and recovery

- MySQL: automated daily backups plus point-in-time recovery; restore into a
  staging environment quarterly and verify migration compatibility.
- Object storage: versioning, lifecycle rules, and cross-region replication if
  required by retention policy.
- Redis: enable AOF persistence. Queues are recoverable from the outbox and
  stage rows, so Redis loss delays work but does not lose source data.
- Define RPO/RTO: e.g. RPO 5 minutes (MySQL PITR), RTO 1 hour (restore + roll
  new containers). Document the runbook and on-call escalation.

## Graceful shutdown and deploys

- Horizon and `outbox:work` handle `SIGTERM`/`SIGINT` and stop claiming new
  work. Give containers a 30-60 second stop grace period so in-flight jobs can
  finish or be retried.
- `failOnTimeout=true` on stage jobs ensures a hung job is failed visibly
  rather than silently killed.
- During deploys, keep old workers alive until their queues are drained; jobs
  are idempotent, so a brief overlap is safe.

## Production checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, strong secret-managed `APP_KEY`.
- [ ] CORS restricted to the SPA origin.
- [ ] MySQL and Redis managed, encrypted, backed up, monitored.
- [ ] Migrations run as a one-shot job; indexes verified.
- [ ] API, Horizon, and outbox run as separate supervised services.
- [ ] Queue depth, outbox lag, stage failures, and provider errors alerted.
- [ ] Upload storage on object storage with signed downloads; malware scan.
- [ ] Load test at expected peak and at 2x peak; verify funnel never exceeds 4.
- [ ] Chaos test: kill workers mid-flight; verify no lost or duplicate side
      effects.
- [ ] Replay runbook exercised with `dlq:list` / `dlq:replay`.
- [ ] Rollback image tag and restore procedure documented and tested.
