const LABELS: Record<string, string> = {
  received: 'Received',
  processing: 'Processing',
  completed: 'Completed',
  failed: 'Failed',
  accepted: 'Accepted',
  rejected: 'Rejected',
  revision_requested: 'Revision requested',
  pending: 'Pending',
  running: 'Running',
  retrying: 'Retrying',
  skipped: 'Skipped',
};

export function StatusBadge({ status }: { status: string }) {
  return <span className={`badge badge-${status}`}>{LABELS[status] ?? status}</span>;
}
