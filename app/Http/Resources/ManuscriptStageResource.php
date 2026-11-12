<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManuscriptStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->stage_name,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
