<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'manuscript_id',
    'event_type',
    'recipient',
    'subject',
    'idempotency_key',
    'status_version',
    'status',
    'attempts',
    'sent_at',
    'last_error',
])]
class EmailDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'status_version' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function manuscript(): BelongsTo
    {
        return $this->belongsTo(Manuscript::class);
    }
}
