import type { Manuscript, Paginated, User } from './types';

const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000';
const TOKEN_KEY = 'manuscript_token';
const USER_KEY = 'manuscript_user';

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function getStoredUser(): User | null {
  const raw = localStorage.getItem(USER_KEY);
  return raw ? (JSON.parse(raw) as User) : null;
}

export function storeSession(token: string, user: User): void {
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(USER_KEY, JSON.stringify(user));
}

export function clearSession(): void {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = getToken();
  const headers = new Headers(init.headers);

  if (token) {
    headers.set('Authorization', `Bearer ${token}`);
  }

  if (!(init.body instanceof FormData) && init.body !== undefined) {
    headers.set('Content-Type', 'application/json');
  }

  const response = await fetch(`${API_URL}${path}`, { ...init, headers });

  if (response.status === 401) {
    clearSession();
    throw new Error('Your session expired. Please sign in again.');
  }

  const body = await response.json().catch(() => ({}));

  if (!response.ok) {
    const detailed = Object.values((body as { errors?: Record<string, string[]> }).errors ?? {})
      .flat()
      .join(' ');
    const message = (body as { message?: string }).message || detailed || `Request failed with ${response.status}`;
    throw new Error(message);
  }

  return body as T;
}

export async function login(email: string, password: string): Promise<{ token: string; user: User }> {
  return request('/api/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  });
}

export async function logout(): Promise<void> {
  try {
    await request('/api/logout', { method: 'POST' });
  } finally {
    clearSession();
  }
}

export async function listMyManuscripts(status = ''): Promise<Paginated<Manuscript>> {
  const query = status ? `?status=${encodeURIComponent(status)}` : '';
  return request(`/api/manuscripts${query}`);
}

export async function listEditorManuscripts(status = ''): Promise<Paginated<Manuscript>> {
  const query = status ? `?status=${encodeURIComponent(status)}` : '';
  return request(`/api/editor/manuscripts${query}`);
}

export async function getManuscript(id: string): Promise<Manuscript> {
  const { data } = await request<{ data: Manuscript }>(`/api/manuscripts/${id}`);

  return data;
}

export async function submitManuscript(form: FormData): Promise<{ id: string; status: string }> {
  return request('/api/manuscripts', { method: 'POST', body: form });
}

export async function updateEditorStatus(
  id: string,
  status: 'accepted' | 'rejected' | 'revision_requested',
): Promise<{ id: string; status: string }> {
  return request(`/api/editor/manuscripts/${id}/status`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  });
}
