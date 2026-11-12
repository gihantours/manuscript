import { useState } from 'react';
import { login } from '../api';
import type { User } from '../types';

export function LoginPage({ onLogin }: { onLogin: (token: string, user: User) => void }) {
  const [email, setEmail] = useState('author@example.com');
  const [password, setPassword] = useState('password');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const result = await login(email, password);
      onLogin(result.token, result.user);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Sign in failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="login-page">
      <form className="card login-card" onSubmit={handleSubmit}>
        <h1>Manuscript Service</h1>
        <p className="muted">Sign in to submit and track manuscripts.</p>

        {error && <div className="error-banner">{error}</div>}

        <label>
          Email
          <input value={email} onChange={(event) => setEmail(event.target.value)} type="email" required />
        </label>
        <label>
          Password
          <input
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            type="password"
            required
          />
        </label>

        <button type="submit" disabled={busy}>
          {busy ? 'Signing in…' : 'Sign in'}
        </button>

        <div className="login-hint">
          <strong>Demo accounts</strong>
          <span>author@example.com / password</span>
          <span>editor@example.com / password</span>
        </div>
      </form>
    </div>
  );
}
