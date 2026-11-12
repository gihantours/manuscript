import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { submitManuscript } from '../api';
import type { User } from '../types';

export function SubmitPage({ user }: { user: User }) {
  const navigate = useNavigate();
  const [title, setTitle] = useState('');
  const [authorEmail, setAuthorEmail] = useState(user.email);
  const [priority, setPriority] = useState<'normal' | 'high'>('normal');
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (!file) {
      setError('Choose a .txt manuscript to upload.');
      return;
    }

    setBusy(true);
    setError(null);

    const form = new FormData();
    form.set('title', title);
    form.set('author_email', authorEmail);
    form.set('priority', priority);
    form.set('file', file);

    try {
      const result = await submitManuscript(form);
      navigate(`/manuscripts/${result.id}`);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Submission failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="page">
      <h1>Submit a manuscript</h1>
      <p className="muted">
        Upload a plain-text file (up to 10 MB). The API returns an ID immediately and processes the
        manuscript in the background.
      </p>

      <form className="card form-grid" onSubmit={handleSubmit}>
        {error && <div className="error-banner full-width">{error}</div>}

        <label>
          Title
          <input value={title} onChange={(event) => setTitle(event.target.value)} required maxLength={255} />
        </label>

        <label>
          Author email
          <input
            value={authorEmail}
            onChange={(event) => setAuthorEmail(event.target.value)}
            type="email"
            required
          />
        </label>

        <label>
          Priority
          <select value={priority} onChange={(event) => setPriority(event.target.value as 'normal' | 'high')}>
            <option value="normal">Normal</option>
            <option value="high">High</option>
          </select>
        </label>

        <label>
          Manuscript (.txt)
          <input
            type="file"
            accept=".txt,text/plain"
            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            required
          />
        </label>

        <button type="submit" disabled={busy}>
          {busy ? 'Uploading…' : 'Submit manuscript'}
        </button>
      </form>
    </section>
  );
}
