<?php

namespace App\Jobs;

use App\Enums\ProcessingStage;
use App\Models\Manuscript;
use App\Models\OutboxEvent;
use App\Services\StageTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExtractTextJob extends ManuscriptStageJob
{
    protected function stage(): ProcessingStage
    {
        return ProcessingStage::TextExtracted;
    }

    protected function perform(Manuscript $manuscript): void
    {
        $raw = Storage::disk('local')->get($manuscript->file_path);
        $text = str_replace(["\r\n", "\r"], "\n", ltrim($raw, "\xEF\xBB\xBF"));
        $normalized = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
        $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $textPath = 'manuscripts/extracted/'.$manuscript->id.'.txt';
        Storage::disk('local')->put($textPath, $normalized);

        DB::transaction(function () use ($manuscript, $normalized, $words, $textPath) {
            $manuscript->forceFill([
                'extracted_text_path' => $textPath,
                'extracted_text_hash' => hash('sha256', $normalized),
                'word_count' => count($words),
            ])->save();

            app(StageTracker::class)->markCompleted($manuscript, $this->stage());

            OutboxEvent::create([
                'event_type' => 'manuscript.text_extracted',
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
