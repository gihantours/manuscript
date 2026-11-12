<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManuscriptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'author_email' => $this->author_email,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'needs_attention' => $this->needs_attention,
            'similarity_score' => $this->similarity_score,
            'word_count' => $this->word_count,
            'preview' => $this->preview,
            'failure_reason' => $this->failure_reason,
            'submitted_at' => $this->created_at?->toIso8601String(),
            'stages' => ManuscriptStageResource::collection($this->whenLoaded('stages')),
        ];
    }
}
