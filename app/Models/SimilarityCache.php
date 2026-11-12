<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'content_hash',
    'score',
])]
class SimilarityCache extends Model
{
    protected $primaryKey = 'content_hash';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }
}
