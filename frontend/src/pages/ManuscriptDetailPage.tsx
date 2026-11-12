import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { getManuscript, updateEditorStatus } from '../api';
import { usePolling } from '../hooks/usePolling';
import { StageTimeline } from '../components/StageTimeline';
import { StatusBadge } from '../components/StatusBadge';
import type { User } from '../types';

const EDITOR_ACTIONS = [
  ['accepted', 'Accept'],
  ['revision_requested', 'Request revision'],
  ['rejected', 'Reject'],
] as const;

export function ManuscriptDetailPage({ user }: { user: User }) {
  const { id } = useParams<{ id: string }>();
  const { data, error, loading, setData } = usePolling(() => getManuscript(id as string), 3000);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const changeStatus = async (status: 'accepted' | 'rejected' | 'revision_requested') => {
    if (!data) {
      return;
    }

    setBusy(true);
    setActionError(null);

    try {
      await updateEditorStatus(data.id, status);
      setData((previous) => (previous ? { ...previous, status } : previous));
    } catch (caught) {
      setActionError(caught instanceof Error ? caught.message : 'Status update failed');
    } finally {
      setBusy(false);
    }
  };

  if (loading && !data) {
    return <section className="page"><p className="muted">Loading manuscript…</p></section>;
  }

  if (error || !data) {
    return (
      <section className="page">
        <div className="error-banner">{error ?? 'Manuscript not found'}</div>
        <Link to="/manuscripts">Back to manuscripts</Link>
      </section>
    );
  }

  return (
    <section className="page">
      <Link to="/manuscripts" className="back-link">
        ← Back to manuscripts
      </Link>

      <div className="page-head">
        <div>
          <h1>{data.title}</h1>
          <p className="muted">
            {data.id} · {data.author_email} · {data.word_count ?? '—'} words
          </p>
        </div>
        <div className="head-badges">
          <StatusBadge status={data.status} />
          <span className={`priority priority-${data.priority}`}>{data.priority}</span>
        </div>
      </div>

      {data.needs_attention && (
        <div className="attention-banner">
          This manuscript needs editor attention
          {data.similarity_score !== null ? ` (similarity ${data.similarity_score})` : ''}.
        </div>
      )}

      {data.failure_reason && <div className="error-banner">{data.failure_reason}</div>}
      {actionError && <div className="error-banner">{actionError}</div>}

      {user.role === 'editor' && (
        <div className="card editor-actions">
          <strong>Editorial decision</strong>
          <div className="button-row">
            {EDITOR_ACTIONS.map(([value, label]) => (
              <button key={value} type="button" disabled={busy} onClick={() => changeStatus(value)}>
                {label}
              </button>
            ))}
          </div>
        </div>
      )}

      <div className="detail-grid">
        <div className="card">
          <h2>Processing stages</h2>
          <StageTimeline stages={data.stages ?? []} />
        </div>

        <div className="card">
          <h2>Preview</h2>
          <p className="preview">{data.preview ?? 'Preview is not available yet.'}</p>
          {data.similarity_score !== null && (
            <div className="meta-block">
              <span>Similarity score</span>
              <strong>{data.similarity_score} / 100</strong>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
