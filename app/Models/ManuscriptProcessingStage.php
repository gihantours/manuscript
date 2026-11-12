<?php

namespace App\Models;

use App\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'manuscript_id',
    'stage_name',
    'status',
    'attempts',
    'started_at',
    'finished_at',
    'last_error',
])]
class ManuscriptProcessingStage extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ProcessingStatus::class,
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function manuscript(): BelongsTo
    {
        return $this->belongsTo(Manuscript::class);
    }
}
