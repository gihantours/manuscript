<?php

namespace App\Services;

use App\Enums\ProcessingStage;
use App\Enums\ProcessingStatus;
use App\Models\Manuscript;
use Illuminate\Support\Facades\DB;

class StageTracker
{
    public function isCompleted(Manuscript $manuscript, ProcessingStage $stage): bool
    {
        $row = $manuscript->stages()->where('stage_name', $stage->value)->first();

        return $row?->status === ProcessingStatus::Completed;
    }

    public function markRunning(Manuscript $manuscript, ProcessingStage $stage): void
    {
        $manuscript->stages()
            ->where('stage_name', $stage->value)
            ->update([
                'status' => ProcessingStatus::Running,
                'started_at' => now(),
                'finished_at' => null,
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
            ]);
    }

    public function markRetrying(Manuscript $manuscript, ProcessingStage $stage, \Throwable $exception): void
    {
        $manuscript->stages()
            ->where('stage_name', $stage->value)
            ->update([
                'status' => ProcessingStatus::Retrying,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
    }

    public function markCompleted(Manuscript $manuscript, ProcessingStage $stage): void
    {
        $manuscript->stages()
            ->where('stage_name', $stage->value)
            ->update([
                'status' => ProcessingStatus::Completed,
                'finished_at' => now(),
                'last_error' => null,
            ]);
    }

    public function markFailed(Manuscript $manuscript, ProcessingStage $stage, \Throwable $exception): void
    {
        $manuscript->stages()
            ->where('stage_name', $stage->value)
            ->update([
                'status' => ProcessingStatus::Failed,
                'finished_at' => now(),
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
    }
}
