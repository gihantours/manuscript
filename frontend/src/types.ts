export type Role = 'author' | 'editor';

export interface User {
  id: number;
  name: string;
  email: string;
  role: Role;
}

export interface Stage {
  name: string;
  status: 'pending' | 'running' | 'retrying' | 'completed' | 'failed' | 'skipped';
  attempts: number;
  started_at: string | null;
  finished_at: string | null;
  last_error: string | null;
}

export interface Manuscript {
  id: string;
  title: string;
  author_email: string;
  priority: 'normal' | 'high';
  status: string;
  needs_attention: boolean;
  similarity_score: number | null;
  word_count: number | null;
  preview: string | null;
  failure_reason: string | null;
  submitted_at: string;
  stages?: Stage[];
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    total: number;
  };
}
