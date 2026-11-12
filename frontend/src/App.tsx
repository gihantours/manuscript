import { useState } from 'react';
import { BrowserRouter, Navigate, NavLink, Route, Routes, useNavigate } from 'react-router-dom';
import { clearSession, getStoredUser, getToken, logout, storeSession } from './api';
import type { User } from './types';
import { LoginPage } from './pages/LoginPage';
import { SubmitPage } from './pages/SubmitPage';
import { ManuscriptsPage } from './pages/ManuscriptsPage';
import { ManuscriptDetailPage } from './pages/ManuscriptDetailPage';
import { EditorPage } from './pages/EditorPage';

function Layout({ user, onLogout }: { user: User; onLogout: () => void }) {
  const navigate = useNavigate();

  const handleLogout = async () => {
    await logout();
    onLogout();
    navigate('/');
  };

  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="brand">Manuscript Service</div>
        <nav>
          <NavLink to="/manuscripts">My manuscripts</NavLink>
          <NavLink to="/submit">Submit</NavLink>
          {user.role === 'editor' && <NavLink to="/editor">Editor</NavLink>}
        </nav>
        <div className="user-box">
          <span>{user.name}</span>
          <span className="role-pill">{user.role}</span>
          <button type="button" onClick={handleLogout}>
            Sign out
          </button>
        </div>
      </header>
      <main>
        <Routes>
          <Route path="/" element={<Navigate to="/manuscripts" replace />} />
          <Route path="/manuscripts" element={<ManuscriptsPage />} />
          <Route path="/manuscripts/:id" element={<ManuscriptDetailPage user={user} />} />
          <Route path="/submit" element={<SubmitPage user={user} />} />
          <Route
            path="/editor"
            element={user.role === 'editor' ? <EditorPage /> : <Navigate to="/manuscripts" replace />}
          />
          <Route path="*" element={<Navigate to="/manuscripts" replace />} />
        </Routes>
      </main>
    </div>
  );
}

export default function App() {
  const [user, setUser] = useState<User | null>(() => (getToken() ? getStoredUser() : null));

  const handleLogin = (token: string, loggedIn: User) => {
    storeSession(token, loggedIn);
    setUser(loggedIn);
  };

  const handleLogout = () => {
    clearSession();
    setUser(null);
  };

  if (!user) {
    return <LoginPage onLogin={handleLogin} />;
  }

  return (
    <BrowserRouter>
      <Layout user={user} onLogout={handleLogout} />
    </BrowserRouter>
  );
}
