<?php

namespace App\Http\Controllers;

use App\Enums\ManuscriptStatus;
use App\Http\Requests\UpdateManuscriptStatusRequest;
use App\Http\Resources\ManuscriptResource;
use App\Models\Manuscript;
use App\Models\OutboxEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditorManuscriptController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isEditor(), 403);

        $manuscripts = Manuscript::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')))
            ->when($request->has('needs_attention'), fn ($query) => $query->where('needs_attention', $request->boolean('needs_attention')))
            ->with('stages')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return ManuscriptResource::collection($manuscripts);
    }

    public function updateStatus(UpdateManuscriptStatusRequest $request, Manuscript $manuscript)
    {
        $this->authorize('updateStatus', $manuscript);

        $newStatus = ManuscriptStatus::from($request->validated('status'));

        $manuscript = DB::transaction(function () use ($manuscript, $newStatus) {
            $locked = Manuscript::query()->lockForUpdate()->findOrFail($manuscript->id);
            $version = $locked->status_version + 1;

            $locked->forceFill([
                'status' => $newStatus,
                'status_version' => $version,
            ])->save();

            OutboxEvent::create([
                'event_type' => 'editor.status_changed',
                'aggregate_id' => (string) $locked->id,
                'payload' => [
                    'manuscript_id' => $locked->id,
                    'priority' => $locked->priority->value,
                    'status' => $newStatus->value,
                    'status_version' => $version,
                ],
                'available_at' => now(),
            ]);

            return $locked;
        });

        Log::info('manuscript.status_changed', [
            'manuscript_id' => $manuscript->public_id,
            'editor_status' => $manuscript->status->value,
            'status_version' => $manuscript->status_version,
            'outcome' => 'success',
        ]);

        return response()->json([
            'id' => $manuscript->public_id,
            'status' => $manuscript->status->value,
            'status_version' => $manuscript->status_version,
        ]);
    }
}
