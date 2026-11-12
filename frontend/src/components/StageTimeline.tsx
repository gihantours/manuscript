import type { Stage } from '../types';
import { StatusBadge } from './StatusBadge';

const STAGE_LABELS: Record<string, string> = {
  received: 'Received',
  validated: 'Validated',
  text_extracted: 'Text extracted',
  similarity_checked: 'Similarity checked',
  preview_built: 'Preview built',
  author_notified: 'Author notified',
  done: 'Done',
};

function formatTime(value: string | null): string {
  if (!value) {
    return '—';
  }

  return new Date(value).toLocaleTimeString();
}

export function StageTimeline({ stages }: { stages: Stage[] }) {
  return (
    <ol className="timeline">
      {stages.map((stage) => (
        <li key={stage.name} className={`timeline-item timeline-${stage.status}`}>
          <div className="timeline-head">
            <strong>{STAGE_LABELS[stage.name] ?? stage.name}</strong>
            <StatusBadge status={stage.status} />
          </div>
          <div className="timeline-meta">
            <span>Attempts: {stage.attempts}</span>
            <span>Started: {formatTime(stage.started_at)}</span>
            <span>Finished: {formatTime(stage.finished_at)}</span>
          </div>
          {stage.last_error && <div className="stage-error">{stage.last_error}</div>}
        </li>
      ))}
    </ol>
  );
}
