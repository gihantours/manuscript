<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'event_type',
    'aggregate_id',
    'payload',
    'available_at',
    'published_at',
    'attempts',
    'last_error',
])]
class OutboxEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
