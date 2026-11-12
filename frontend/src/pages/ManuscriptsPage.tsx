import { useState } from 'react';
import { Link } from 'react-router-dom';
import { listMyManuscripts } from '../api';
import { usePolling } from '../hooks/usePolling';
import { StatusBadge } from '../components/StatusBadge';

const STATUSES = ['', 'received', 'processing', 'completed', 'failed', 'accepted', 'rejected', 'revision_requested'];

export function ManuscriptsPage() {
  const [status, setStatus] = useState('');
  const { data, error, loading } = usePolling(() => listMyManuscripts(status), 4000);

  return (
    <section className="page">
      <div className="page-head">
        <div>
          <h1>My manuscripts</h1>
          <p className="muted">Updates automatically every few seconds.</p>
        </div>
        <Link className="button-link" to="/submit">
          Submit manuscript
        </Link>
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

      {error && <div className="error-banner">{error}</div>}
      {loading && !data && <div className="muted">Loading manuscripts…</div>}

      {data && data.data.length === 0 && (
        <div className="card empty-state">
          <h2>No manuscripts yet</h2>
          <p className="muted">Submit your first manuscript to see it move through the pipeline.</p>
        </div>
      )}

      <div className="manuscript-list">
        {data?.data.map((manuscript) => (
          <Link key={manuscript.id} to={`/manuscripts/${manuscript.id}`} className="card manuscript-card">
            <div className="card-head">
              <strong>{manuscript.title}</strong>
              <StatusBadge status={manuscript.status} />
            </div>
            <div className="card-meta">
              <span>{manuscript.id}</span>
              <span className={`priority priority-${manuscript.priority}`}>{manuscript.priority}</span>
              {manuscript.needs_attention && <span className="attention">Needs attention</span>}
              {manuscript.similarity_score !== null && (
                <span>Similarity {manuscript.similarity_score}</span>
              )}
            </div>
            <div className="card-meta">
              <span>{manuscript.author_email}</span>
              <span>Submitted {new Date(manuscript.submitted_at).toLocaleString()}</span>
            </div>
          </Link>
        ))}
      </div>
    </section>
  );
}
