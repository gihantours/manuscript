<?php

namespace App\Jobs;

use App\Enums\ManuscriptStatus;
use App\Enums\ProcessingStage;
use App\Models\Manuscript;
use App\Services\StageTracker;
use Illuminate\Support\Facades\DB;

class FinalizeManuscriptJob extends ManuscriptStageJob
{
    protected function stage(): ProcessingStage
    {
        return ProcessingStage::Done;
    }

    protected function perform(Manuscript $manuscript): void
    {
        DB::transaction(function () use ($manuscript) {
            app(StageTracker::class)->markCompleted($manuscript, $this->stage());

            $fresh = Manuscript::query()->lockForUpdate()->findOrFail($manuscript->id);

            if (! $fresh->isEditorialStatus() && $fresh->status !== ManuscriptStatus::Failed) {
                $fresh->forceFill(['status' => ManuscriptStatus::Completed])->save();
            }
        });
    }
}
