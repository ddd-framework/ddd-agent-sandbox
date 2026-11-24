// Labels, states and numbers of the sandbox screens. Pure: no React.
import { Archive, CircleCheck, CircleDot, CircleX, Clock, Moon, Play, ShieldAlert } from 'lucide-react';

import { formatUiNumber } from '@mgamadeus/ddd-admin-ui/i18n';
import { m } from '@mgamadeus/ddd-admin-ui/i18n';
import { defineStatusMap } from '@mgamadeus/ddd-admin-ui/logic';
import type { SandboxActionOutcome, SandboxCommand, SandboxCommandStatus, SandboxState, SandboxWorkspace } from '../types.ts';

/** `SandboxWorkspace.state`. */
export const SANDBOX_STATE = defineStatusMap<SandboxState>({
  RUNNING: {
    label: m('Running', { context: 'sandbox-state' }),
    tone: 'success',
    icon: Play,
    description: m('A session is open: commands run and files live on the runner', { context: 'sandbox-state' }),
  },
  IDLE: {
    label: m('Idle', { context: 'sandbox-state' }),
    tone: 'info',
    icon: Moon,
    description: m('The session is open but nothing ran for a while', { context: 'sandbox-state' }),
  },
  OFFLOADED: {
    label: m('Offloaded', { context: 'sandbox-state' }),
    tone: 'neutral',
    icon: Archive,
    description: m('No session: the workspace is saved and starts again on the next command', { context: 'sandbox-state' }),
  },
}, { context: 'sandbox-state' });

/** `SandboxActionLogEntry.outcome`. */
export const SANDBOX_ACTION_OUTCOME = defineStatusMap<SandboxActionOutcome>({
  SUCCEEDED: { label: m('Done', { context: 'sandbox-action-outcome' }), tone: 'success', icon: CircleCheck },
  REFUSED: {
    label: m('Refused', { context: 'sandbox-action-outcome' }),
    tone: 'warning',
    icon: ShieldAlert,
    description: m('Not run: a turn was running, a confirmation or a reason was missing', { context: 'sandbox-action-outcome' }),
  },
  FAILED: { label: m('Failed', { context: 'sandbox-action-outcome' }), tone: 'danger', icon: CircleX },
}, { context: 'sandbox-action-outcome' });

/**
 * The state of a command: the server's `status`, else derived from the exit code — an exit code
 * is EXITED, none is UNKNOWN (a killed or timed-out command has none either, so it is never shown as running).
 */
export type SandboxCommandState = SandboxCommandStatus | 'UNKNOWN';

export const sandboxCommandStateOf = (command: Pick<SandboxCommand, 'status' | 'exitCode'>): SandboxCommandState => {
  if (command.status) return command.status;
  return command.exitCode === null || command.exitCode === undefined ? 'UNKNOWN' : 'EXITED';
};

/** `SandboxCommand.status` as a badge (EXITED carries the exit code next to it). */
export const SANDBOX_COMMAND_STATE = defineStatusMap<SandboxCommandState>({
  RUNNING: { label: m('Running', { context: 'sandbox-command-state' }), tone: 'info', icon: Play },
  EXITED: { label: m('Exited', { context: 'sandbox-command-state' }), tone: 'neutral', icon: CircleCheck },
  KILLED: {
    label: m('Killed', { context: 'sandbox-command-state' }),
    tone: 'warning',
    icon: CircleX,
    description: m('The command was stopped: its job was killed, the session ended or a limit was hit', { context: 'sandbox-command-state' }),
  },
  TIMED_OUT: {
    label: m('Timed out', { context: 'sandbox-command-state' }),
    tone: 'danger',
    icon: Clock,
    description: m('The runner stopped the command at its time limit', { context: 'sandbox-command-state' }),
  },
  UNKNOWN: {
    label: m('State unknown', { context: 'sandbox-command-state' }),
    tone: 'neutral',
    icon: CircleDot,
    description: m('The server reports no state and no exit code for this command', { context: 'sandbox-command-state' }),
  },
}, { context: 'sandbox-command-state' });

/** The translation context of `SANDBOX_ACTION_LABELS`. */
export const SANDBOX_ACTION_LABEL_CONTEXT = 'sandbox-action';

/** The translation context of `SANDBOX_CHANGE_LABELS`. */
export const SANDBOX_CHANGE_LABEL_CONTEXT = 'sandbox-change';

/** The actions of the audit, as the screen names them. */
export const SANDBOX_ACTION_LABELS: Readonly<Record<string, string>> = {
  START: m('Start session', { context: 'sandbox-action' }),
  OFFLOAD: m('Offload', { context: 'sandbox-action' }),
  KILL_JOB: m('Kill job', { context: 'sandbox-action' }),
  END: m('End session', { context: 'sandbox-action' }),
  RESET: m('Reset workspace', { context: 'sandbox-action' }),
};

/** How a drive change of the workspace is named. */
export const SANDBOX_CHANGE_LABELS: Readonly<Record<string, string>> = {
  ADDED: m('Added', { context: 'sandbox-change' }),
  MODIFIED: m('Changed', { context: 'sandbox-change' }),
  DELETED: m('Deleted', { context: 'sandbox-change' }),
  RENAMED: m('Renamed', { context: 'sandbox-change' }),
};

/** The icon of a session log entry: a limit hit is a warning, everything else a dot. */
export const sessionEventIconOf = (type: string) => (/EXCEEDED|DENIED|TIMEOUT|OOM|KILLED/i.test(type) ? ShieldAlert : CircleDot);

/** `1.5 GB` in the UI language; `—` for unknown. */
export const formatSandboxBytes = (bytes: number | null | undefined): string => {
  if (bytes === null || bytes === undefined || !Number.isFinite(bytes)) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return `${formatUiNumber(value, { maximumFractionDigits: unit === 0 || value >= 100 ? 0 : 1 })} ${units[unit]}`;
};

/** `850 ms`, `12.4 s`, `3 min 5 s`; `—` for unknown. */
export const formatSandboxDuration = (milliseconds: number | null | undefined): string => {
  if (milliseconds === null || milliseconds === undefined || !Number.isFinite(milliseconds)) return '—';
  if (milliseconds < 1000) return `${formatUiNumber(Math.round(milliseconds))} ms`;
  const seconds = milliseconds / 1000;
  if (seconds < 60) return `${formatUiNumber(seconds, { maximumFractionDigits: 1 })} s`;
  const minutes = Math.floor(seconds / 60);
  return `${formatUiNumber(minutes)} min ${formatUiNumber(Math.round(seconds - minutes * 60))} s`;
};

/** The share of a limit in percent (0–100, rounded), `null` without a limit. */
export const usagePercentOf = (used: number | null | undefined, limit: number | null | undefined): number | null =>
  used === null || used === undefined || !limit || limit <= 0 ? null : Math.min(100, Math.max(0, Math.round((used / limit) * 100)));

/** The name a workspace is shown with: its display name, else its key. */
export const sandboxNameOf = (workspace: Pick<SandboxWorkspace, 'displayName' | 'workspaceKey'>): string =>
  workspace.displayName?.trim() ? workspace.displayName : workspace.workspaceKey;

/** What a reset asks the reader to type: the display name, or the key without one (the server checks the same). */
export const sandboxResetPhraseOf = (workspace: Pick<SandboxWorkspace, 'displayName' | 'workspaceKey'>): string => sandboxNameOf(workspace);

/** Whether an error is the server's "a turn is running" refusal (409): the action may be forced with a reason. */
export const isSandboxTurnRunningRefusal = (error: unknown): boolean => (error as { status?: unknown } | null)?.status === 409;

/** The parent of a workspace path (`a/b/c` → `a/b`, `a` → `''`). */
export const sandboxParentPathOf = (path: string): string => {
  const trimmed = path.replace(/\/+$/, '');
  const index = trimmed.lastIndexOf('/');
  return index <= 0 ? '' : trimmed.slice(0, index);
};

/** The crumbs of a workspace directory: root first. */
export const sandboxPathCrumbs = (path: string): { name: string; path: string }[] => {
  const parts = path.split('/').filter(part => part !== '');
  return parts.map((name, index) => ({ name, path: parts.slice(0, index + 1).join('/') }));
};

/** How a file is previewed, from the type the server sent with its bytes. */
export type SandboxPreviewKind = 'image' | 'pdf' | 'text' | 'none';

export const sandboxPreviewKindOf = (contentType: string): SandboxPreviewKind => {
  const type = contentType.split(';')[0]?.trim().toLowerCase() ?? '';
  if (type === 'image/jpeg' || type === 'image/png' || type === 'image/gif' || type === 'image/webp') return 'image';
  if (type === 'application/pdf') return 'pdf';
  if (type === 'text/plain') return 'text';
  return 'none';
};

/** The inline text preview of the server stops here; a larger answer was cut. */
export const SANDBOX_TEXT_PREVIEW_BYTES = 256 * 1024;
