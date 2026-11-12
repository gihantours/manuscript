<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManuscriptRequest;
use App\Http\Resources\ManuscriptResource;
use App\Models\Manuscript;
use App\Services\ManuscriptSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ManuscriptController extends Controller
{
    public function store(StoreManuscriptRequest $request, ManuscriptSubmissionService $service)
    {
        $startedAt = microtime(true);

        $manuscript = $service->submit(
            $request->user(),
            $request->file('file'),
            $request->validated(),
        );

        Log::info('manuscript.submitted', [
            'manuscript_id' => $manuscript->public_id,
            'priority' => $manuscript->priority->value,
            'outcome' => 'accepted',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json([
            'id' => $manuscript->public_id,
            'status' => $manuscript->status->value,
        ], 202);
    }

    public function index(Request $request)
    {
        $manuscripts = $request->user()
            ->manuscripts()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')))
            ->when($request->has('needs_attention'), fn ($query) => $query->where('needs_attention', $request->boolean('needs_attention')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return ManuscriptResource::collection($manuscripts);
    }

    public function show(Manuscript $manuscript)
    {
        $this->authorize('view', $manuscript);

        return new ManuscriptResource($manuscript->load('stages'));
    }
}
