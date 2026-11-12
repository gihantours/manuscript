<?php

namespace Tests\Feature;

use App\Jobs\CheckSimilarityJob;
use App\Jobs\NotifyEditorStatusJob;
use App\Jobs\ValidateManuscriptJob;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_redis_visibility_timeout_exceeds_every_job_and_horizon_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $timeouts = [
            (new ValidateManuscriptJob(1))->timeout,
            (new CheckSimilarityJob(1))->timeout,
            (new NotifyEditorStatusJob(1, 1, 'accepted'))->timeout,
        ];

        foreach (config('horizon.defaults') as $supervisor) {
            $timeouts[] = (int) $supervisor['timeout'];
        }

        $this->assertGreaterThan(
            max($timeouts),
            $retryAfter,
            'The Redis visibility timeout must exceed every job and Horizon timeout to avoid redelivering in-flight work.',
        );
    }
}
