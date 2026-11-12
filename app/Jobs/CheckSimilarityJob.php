<?php

namespace App\Jobs;

use App\Enums\ProcessingStage;
use App\Exceptions\RateLimitException;
use App\Models\Manuscript;
use App\Models\OutboxEvent;
use App\Models\SimilarityCache;
use App\Services\SimilarityService;
use App\Services\StageTracker;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class CheckSimilarityJob extends ManuscriptStageJob
{
    protected function stage(): ProcessingStage
    {
        return ProcessingStage::SimilarityChecked;
    }

    protected function perform(Manuscript $manuscript): void
    {
        if ($manuscript->extracted_text_path === null || ! Storage::disk('local')->exists($manuscript->extracted_text_path)) {
            throw new \RuntimeException('Extracted text is not available.');
        }

        $text = Storage::disk('local')->get($manuscript->extracted_text_path);
        $contentHash = $manuscript->extracted_text_hash ?: hash('sha256', $text);

        $cached = SimilarityCache::find($contentHash);

        if ($cached !== null) {
            Log::info('similarity.cache_hit', [
                'manuscript_id' => $manuscript->public_id,
                'score' => $cached->score,
            ]);

            $this->persist($manuscript, $cached->score);

            return;
        }

        $limit = (int) config('services.similarity.concurrency', 4);

        try {
            $score = Redis::funnel('manuscript-similarity')
                ->limit($limit)
                ->releaseAfter(120)
                ->block(45)
                ->then(fn () => app(SimilarityService::class)->score($manuscript->public_id, $text));
        } catch (LimiterTimeoutException) {
            throw new RateLimitException(15);
        }

        $score = (int) $score;

        SimilarityCache::updateOrCreate(
            ['content_hash' => $contentHash],
            ['score' => $score],
        );

        $this->persist($manuscript, $score);
    }

    private function persist(Manuscript $manuscript, int $score): void
    {
        DB::transaction(function () use ($manuscript, $score) {
            $manuscript->forceFill([
                'similarity_score' => $score,
                'needs_attention' => $score >= 70,
            ])->save();

            app(StageTracker::class)->markCompleted($manuscript, $this->stage());

            OutboxEvent::create([
                'event_type' => 'manuscript.similarity_checked',
                'aggregate_id' => (string) $manuscript->id,
                'payload' => [
                    'manuscript_id' => $manuscript->id,
                    'priority' => $manuscript->priority->value,
                ],
                'available_at' => now(),
            ]);
        });
    }
}
