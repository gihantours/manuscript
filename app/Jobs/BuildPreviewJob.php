<?php

namespace App\Jobs;

use App\Enums\ProcessingStage;
use App\Models\Manuscript;
use App\Models\OutboxEvent;
use App\Services\StageTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BuildPreviewJob extends ManuscriptStageJob
{
    protected function stage(): ProcessingStage
    {
        return ProcessingStage::PreviewBuilt;
    }

    protected function perform(Manuscript $manuscript): void
    {
        if ($manuscript->extracted_text_path === null || ! Storage::disk('local')->exists($manuscript->extracted_text_path)) {
            throw new \RuntimeException('Extracted text is not available.');
        }

        $text = Storage::disk('local')->get($manuscript->extracted_text_path);
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $preview = implode(' ', array_slice($words, 0, 200));

        DB::transaction(function () use ($manuscript, $preview) {
            $manuscript->forceFill(['preview' => $preview])->save();

            app(StageTracker::class)->markCompleted($manuscript, $this->stage());

            OutboxEvent::create([
                'event_type' => 'manuscript.preview_built',
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
