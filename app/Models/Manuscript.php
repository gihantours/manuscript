<?php

namespace App\Models;

use App\Enums\ManuscriptStatus;
use App\Enums\Priority;
use App\Enums\ProcessingStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id',
    'user_id',
    'title',
    'author_email',
    'original_filename',
    'file_path',
    'file_hash',
    'extracted_text_path',
    'extracted_text_hash',
    'priority',
    'status',
    'status_version',
    'needs_attention',
    'similarity_score',
    'word_count',
    'preview',
    'failure_reason',
])]
class Manuscript extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status' => ManuscriptStatus::class,
            'status_version' => 'integer',
            'needs_attention' => 'boolean',
            'similarity_score' => 'integer',
            'word_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ManuscriptProcessingStage::class)->orderBy('id');
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(EmailDelivery::class);
    }

    public function stage(ProcessingStage $name): ?ManuscriptProcessingStage
    {
        return $this->stages->firstWhere('stage_name', $name);
    }

    public function isEditorialStatus(): bool
    {
        return in_array($this->status, [
            ManuscriptStatus::Accepted,
            ManuscriptStatus::Rejected,
            ManuscriptStatus::RevisionRequested,
        ], true);
    }
}
