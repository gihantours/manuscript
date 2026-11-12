import { useState } from 'react';
import { Link } from 'react-router-dom';
import { listEditorManuscripts, updateEditorStatus } from '../api';
import { usePolling } from '../hooks/usePolling';
import { StatusBadge } from '../components/StatusBadge';

const STATUSES = ['', 'received', 'processing', 'completed', 'failed', 'accepted', 'rejected', 'revision_requested'];

const ACTIONS = [
  ['accepted', 'Accept'],
  ['revision_requested', 'Revision'],
  ['rejected', 'Reject'],
] as const;

export function EditorPage() {
  const [status, setStatus] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { data, error: loadError, loading, setData } = usePolling(
    () => listEditorManuscripts(status),
    4000,
  );

  const changeStatus = async (
    id: string,
    next: 'accepted' | 'rejected' | 'revision_requested',
  ) => {
    setBusyId(id);
    setError(null);

    try {
      await updateEditorStatus(id, next);
      setData((previous) =>
        previous
          ? {
              ...previous,
              data: previous.data.map((manuscript) =>
                manuscript.id === id ? { ...manuscript, status: next } : manuscript,
              ),
            }
          : previous,
      );
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Status update failed');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <section className="page">
      <div className="page-head">
        <div>
          <h1>Editor queue</h1>
          <p className="muted">Status changes are committed immediately; the author email is queued.</p>
        </div>
      </div>

      <div className="filters">
        <label>
          Status
          <select value={status} onChange={(event) => setStatus(event.target.value)}>
            {STATUSES.map((value) => (
              <option key={value || 'all'} value={value}>
                {value || 'All'}
              </option>
            ))}
          </select>
        </label>
      </div>

      {(error ?? loadError) && <div className="error-banner">{error ?? loadError}</div>}
      {loading && !data && <p className="muted">Loading manuscripts…</p>}

      {data && data.data.length === 0 && (
        <div className="card empty-state">
          <h2>No manuscripts match</h2>
          <p className="muted">Try another filter or submit a manuscript.</p>
        </div>
      )}

      <div className="manuscript-list">
        {data?.data.map((manuscript) => (
          <div key={manuscript.id} className="card manuscript-card">
            <div className="card-head">
              <Link to={`/manuscripts/${manuscript.id}`}>{manuscript.title}</Link>
              <StatusBadge status={manuscript.status} />
            </div>
            <div className="card-meta">
              <span>{manuscript.id}</span>
              <span className={`priority priority-${manuscript.priority}`}>{manuscript.priority}</span>
              {manuscript.needs_attention && (
                <span className="attention">
                  Needs attention{manuscript.similarity_score !== null ? ` (${manuscript.similarity_score})` : ''}
                </span>
              )}
              <span>{manuscript.author_email}</span>
            </div>
            {manuscript.failure_reason && <div className="stage-error">{manuscript.failure_reason}</div>}
            <div className="button-row">
              {ACTIONS.map(([value, label]) => (
                <button
                  key={value}
                  type="button"
                  disabled={busyId === manuscript.id}
                  onClick={() => changeStatus(manuscript.id, value)}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}
