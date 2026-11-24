'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/contract';
import { Archive, ChevronRight, File, Folder, Link2, OctagonX, Play, Power, RefreshCw, RotateCcw } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import type { PdfJsLoader } from '@mgamadeus/ddd-admin-ui/files';
import { m } from '@mgamadeus/ddd-admin-ui/i18n';
import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { DateCell } from '@mgamadeus/ddd-admin-ui/list';
import { EmptyValue } from '@mgamadeus/ddd-admin-ui/list';
import { EntityListBody } from '@mgamadeus/ddd-admin-ui/list';
import { EntityListCard } from '@mgamadeus/ddd-admin-ui/list';
import type { EntityListColumn } from '@mgamadeus/ddd-admin-ui/list';
import { StatusBadge } from '@mgamadeus/ddd-admin-ui/list';
import { DefinitionList } from '@mgamadeus/ddd-admin-ui/page';
import { PageHeader } from '@mgamadeus/ddd-admin-ui/page';
import { SectionHeader } from '@mgamadeus/ddd-admin-ui/page';
import { StatusCallout } from '@mgamadeus/ddd-admin-ui/page';
import { Badge } from '@mgamadeus/ddd-admin-ui/primitives';
import { Button } from '@mgamadeus/ddd-admin-ui/primitives';
import { Card, CardContent } from '@mgamadeus/ddd-admin-ui/primitives';
import { cn } from '@mgamadeus/ddd-admin-ui/primitives';
import { useLinkComponent } from '@mgamadeus/ddd-admin-ui/primitives';
import { Skeleton } from '@mgamadeus/ddd-admin-ui/primitives';
import type { SandboxesApi } from '../api.ts';
import {
  formatSandboxBytes,
  formatSandboxDuration,
  SANDBOX_ACTION_LABELS,
  SANDBOX_ACTION_OUTCOME,
  SANDBOX_CHANGE_LABELS,
  SANDBOX_COMMAND_STATE,
  SANDBOX_STATE,
  sandboxCommandStateOf,
  sandboxNameOf,
  sandboxParentPathOf,
  sandboxPathCrumbs,
  sessionEventIconOf,
} from './format.ts';
import { SandboxFilePreview } from './SandboxFilePreview.tsx';
import { SandboxMountMode, SandboxUsageBar } from './SandboxesScreen.tsx';
import type {
  SandboxActionLogEntry,
  SandboxActionOutcome,
  SandboxState,
  SandboxCommand,
  SandboxConversation,
  SandboxDriveChange,
  SandboxFileEntry,
  SandboxProcess,
  SandboxWorkspace,
} from '../types.ts';
import { useSandboxActions } from './useSandboxActions.tsx';

/** The tabs of a workspace. */
export const SANDBOX_DETAIL_TABS = [
  { id: 'files', label: m('Files', { context: 'sandbox-tab' }), context: 'sandbox-tab' },
  { id: 'changes', label: m('Unsaved changes', { context: 'sandbox-tab' }), context: 'sandbox-tab' },
  { id: 'processes', label: m('Processes & jobs', { context: 'sandbox-tab' }), context: 'sandbox-tab' },
  { id: 'commands', label: m('Commands', { context: 'sandbox-tab' }), context: 'sandbox-tab' },
  { id: 'conversations', label: m('Conversations', { context: 'sandbox-tab' }), context: 'sandbox-tab' },
  { id: 'events', label: m('Session log', { context: 'sandbox-tab' }), context: 'sandbox-tab' },
  { id: 'audit', label: m('Audit', { context: 'sandbox-tab' }), context: 'sandbox-tab' },
] as const;

export type SandboxDetailTab = (typeof SANDBOX_DETAIL_TABS)[number]['id'];

const COMMAND_LIMIT = 50;
const LOG_LIMIT = 100;

const errorText = (error: unknown, fallback: string) => normalizeDddError(error, fallback).message;

/** Text that came from the sandbox (a command, its output): escaped, preformatted, never interpreted. */
const UntrustedText = ({ text, className }: { text: string | null | undefined; className?: string }) =>
  text ? <pre className={cn('max-h-64 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs whitespace-pre-wrap break-all', className)}>{text}</pre> : null;

const FilesTab = ({ api, workspaceKey, loadPdfJs }: { api: SandboxesApi; workspaceKey: string; loadPdfJs?: PdfJsLoader }) => {
  const { t } = useTranslate();
  const [directory, setDirectory] = useState('');
  const [openFile, setOpenFile] = useState<SandboxFileEntry | null>(null);
  const filesQuery = api.useSandboxFilesQuery({ workspaceKey, path: directory });
  const entries = [...(filesQuery.data?.entries?.elements ?? [])].sort((a, b) =>
    a.type === b.type ? a.name.localeCompare(b.name) : a.type === 'DIRECTORY' ? -1 : b.type === 'DIRECTORY' ? 1 : 0
  );
  const columns: EntityListColumn<SandboxFileEntry>[] = [
    {
      id: 'name',
      header: t('Name', { context: 'sandbox-file' }),
      width: '3fr',
      cell: entry => (
        <span className="flex min-w-0 items-center gap-2">
          {entry.type === 'DIRECTORY' ? <Folder className="size-4 shrink-0 text-muted-foreground" /> : <File className="size-4 shrink-0 text-muted-foreground" />}
          <span className="truncate font-mono text-[13px]">{entry.name}</span>
          {entry.type === 'SYMLINK' && <Badge variant="outline">{t('Link', { context: 'sandbox-file' })}</Badge>}
        </span>
      ),
    },
    { id: 'size', header: t('Size', { context: 'sandbox-file' }), width: 'content', align: 'end', cell: entry => (entry.type === 'DIRECTORY' ? <EmptyValue /> : formatSandboxBytes(entry.sizeBytes)) },
    { id: 'modified', header: t('Modified', { context: 'sandbox-file' }), width: 'content', cell: entry => <DateCell value={entry.modifiedAt} /> },
  ];
  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
      <EntityListCard
        label={m('Files', { context: 'sandbox-tab' })}
        labelContext="sandbox-tab"
        toolbar={
          <nav aria-label={t('Folder path')} className="flex min-w-0 flex-wrap items-center gap-1 px-1 text-[13px]">
            <button type="button" className="rounded px-1 font-semibold hover:bg-muted" onClick={() => setDirectory('')}>
              {t('Workspace', { context: 'sandbox' })}
            </button>
            {sandboxPathCrumbs(directory).map(crumb => (
              <span key={crumb.path} className="flex items-center gap-1">
                <ChevronRight className="size-3.5 text-muted-foreground" />
                <button type="button" className="rounded px-1 font-mono hover:bg-muted" onClick={() => setDirectory(crumb.path)}>
                  {crumb.name}
                </button>
              </span>
            ))}
          </nav>
        }
      >
        <EntityListBody
          displayMode="table"
          columns={columns}
          items={directory === '' ? entries : [{ name: '..', path: sandboxParentPathOf(directory), type: 'DIRECTORY' }, ...entries]}
          getRowKey={entry => `${entry.type}:${entry.path}:${entry.name}`}
          rowAction={entry => () => {
            if (entry.type === 'DIRECTORY') {
              setDirectory(entry.path);
              setOpenFile(null);
            } else {
              setOpenFile(entry);
            }
          }}
          isLoading={filesQuery.isLoading}
          emptyMessage={t('This folder is empty.')}
          loadingMessage={t('Loading files')}
          error={filesQuery.isError ? errorText(filesQuery.error, t('The files could not be loaded.')) : undefined}
        />
      </EntityListCard>
      <Card>
        <CardContent className="pt-6">
          {openFile ? (
            <SandboxFilePreview api={api} workspaceKey={workspaceKey} file={openFile} loadPdfJs={loadPdfJs} />
          ) : (
            <p className="py-10 text-center text-[13px] text-muted-foreground">{t('Pick a file to see it. Files are shown read only.')}</p>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

const ChangesTab = ({ api, workspaceKey }: { api: SandboxesApi; workspaceKey: string }) => {
  const { t } = useTranslate();
  const statusQuery = api.useSandboxDriveStatusQuery({ workspaceKey });
  const status = statusQuery.data?.driveStatus;
  const columns: EntityListColumn<SandboxDriveChange>[] = [
    {
      id: 'type',
      header: t('Change', { context: 'sandbox-change' }),
      width: 'content',
      cell: change => <Badge variant="outline">{SANDBOX_CHANGE_LABELS[change.changeType] ? t(SANDBOX_CHANGE_LABELS[change.changeType] ?? '', { context: 'sandbox-change' }) : change.changeType}</Badge>,
    },
    {
      id: 'path',
      header: t('Path', { context: 'sandbox-change' }),
      width: '3fr',
      cell: change => (
        <span className="min-w-0 font-mono text-[13px]">
          {change.previousPath && <span className="block truncate text-muted-foreground">{change.previousPath} →</span>}
          <span className="block truncate">{change.path}</span>
        </span>
      ),
    },
    { id: 'size', header: t('Size', { context: 'sandbox-file' }), width: 'content', align: 'end', cell: change => formatSandboxBytes(change.sizeBytes) },
  ];
  if (status && !status.isDriveWorkspace) {
    return <StatusCallout tone="info" title={t('No drive in this workspace')} description={t('Its files are not synced with a drive, so nothing is pending.')} />;
  }
  return (
    <EntityListCard
      label={m('Unsaved changes', { context: 'sandbox-tab' })}
      labelContext="sandbox-tab"
      toolbar={
        status ? (
          <p className="px-1 text-[12.5px] text-muted-foreground">
            {t('{count, plural, one {# change not yet in the drive} other {# changes not yet in the drive}}', { count: status.pendingChangeCount })}
            {status.lastCommittedAt && (
              <>
                {' · '}
                {t('Last saved')} <DateCell value={status.lastCommittedAt} />
              </>
            )}
          </p>
        ) : undefined
      }
    >
      <EntityListBody
        displayMode="table"
        columns={columns}
        items={status?.changes?.elements ?? []}
        getRowKey={change => `${change.changeType}:${change.previousPath ?? ''}:${change.path}`}
        isLoading={statusQuery.isLoading}
        emptyMessage={t('Everything is saved in the drive.')}
        loadingMessage={t('Loading the changes')}
        error={statusQuery.isError ? errorText(statusQuery.error, t('The changes could not be loaded.')) : undefined}
      />
    </EntityListCard>
  );
};

const ProcessesTab = ({
  api,
  workspace,
  onKill,
  killDisabled,
}: {
  api: SandboxesApi;
  workspace: SandboxWorkspace;
  onKill: (jobId: string) => void;
  killDisabled: boolean;
}) => {
  const { t } = useTranslate();
  const processesQuery = api.useSandboxProcessesQuery({ workspaceKey: workspace.workspaceKey });
  const columns: EntityListColumn<SandboxProcess>[] = [
    { id: 'pid', header: 'PID', width: 'content', cell: process => <span className="font-mono text-xs">{process.pid}</span> },
    { id: 'command', header: t('Command', { context: 'sandbox' }), width: '3fr', cell: process => <span className="block truncate font-mono text-xs" title={process.command}>{process.command}</span> },
    { id: 'job', header: t('Job', { context: 'sandbox' }), width: 'content', cell: process => (process.jobId ? <span className="font-mono text-xs">{process.jobId}</span> : <EmptyValue />) },
    { id: 'started', header: t('Started', { context: 'sandbox' }), width: 'content', cell: process => <DateCell value={process.startedAt} /> },
    { id: 'cpu', header: 'CPU', width: 'content', align: 'end', cell: process => formatSandboxDuration(process.cpuSeconds === null || process.cpuSeconds === undefined ? null : process.cpuSeconds * 1000) },
    { id: 'memory', header: t('Memory', { context: 'sandbox' }), width: 'content', align: 'end', cell: process => formatSandboxBytes(process.rssBytes) },
    {
      id: 'actions',
      header: <span className="sr-only">{t('Actions', { context: 'table-column' })}</span>,
      width: 'content',
      pin: 'end',
      cell: process =>
        process.jobId ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="text-destructive hover:text-destructive"
            disabled={killDisabled}
            onClick={() => process.jobId && onKill(process.jobId)}
          >
            <OctagonX />
            {t('Kill job', { context: 'sandbox-action' })}
          </Button>
        ) : null,
    },
  ];
  return (
    <EntityListCard label={m('Processes & jobs', { context: 'sandbox-tab' })} labelContext="sandbox-tab">
      <EntityListBody
        displayMode="table"
        columns={columns}
        items={processesQuery.data?.processes?.elements ?? []}
        getRowKey={process => String(process.pid)}
        isLoading={processesQuery.isLoading}
        emptyMessage={workspace.state === 'OFFLOADED' ? t('No session: nothing runs.') : t('Nothing is running.')}
        loadingMessage={t('Loading the processes')}
        error={processesQuery.isError ? errorText(processesQuery.error, t('The processes could not be loaded.')) : undefined}
      />
    </EntityListCard>
  );
};

const CommandsTab = ({ api, workspaceKey }: { api: SandboxesApi; workspaceKey: string }) => {
  const { t } = useTranslate();
  const commandsQuery = api.useSandboxCommandsQuery({ workspaceKey, limit: COMMAND_LIMIT });
  const commands = commandsQuery.data?.commands?.elements ?? [];
  if (commandsQuery.isLoading) return <Skeleton className="h-48 w-full rounded-xl" />;
  if (commandsQuery.isError) return <StatusCallout tone="destructive" title={t('The commands could not be loaded.')} description={errorText(commandsQuery.error, '')} />;
  if (commands.length === 0) return <p className="py-10 text-center text-[13px] text-muted-foreground">{t('No command ran in this session yet.')}</p>;
  return (
    <ol className="space-y-3">
      {commands.map((command: SandboxCommand) => (
        <li key={command.seq} className="surface space-y-2 rounded-2xl p-4">
          <div className="flex flex-wrap items-center gap-2 text-[12.5px]">
            <StatusBadge value={sandboxCommandStateOf(command)} map={SANDBOX_COMMAND_STATE} />
            {sandboxCommandStateOf(command) === 'EXITED' && command.exitCode !== null && command.exitCode !== undefined && (
              <Badge variant={command.exitCode === 0 ? 'outline' : 'destructive'} className="font-mono">
                {t('exit %code%', { context: 'sandbox-command', replacements: { code: String(command.exitCode) } })}
              </Badge>
            )}
            <span className="text-muted-foreground">#{command.seq}</span>
            <DateCell value={command.startedAt} />
            <span className="text-muted-foreground">{formatSandboxDuration(command.durationMs)}</span>
            {command.jobId && <span className="font-mono text-xs text-muted-foreground">{t('job %id%', { replacements: { id: command.jobId } })}</span>}
            {command.isTruncated && <Badge variant="outline">{t('Output cut', { context: 'sandbox-command' })}</Badge>}
          </div>
          <UntrustedText text={command.command} className="bg-card" />
          {command.stdoutTail && (
            <details>
              <summary className="cursor-pointer text-xs font-semibold text-muted-foreground">{t('Output', { context: 'sandbox-command' })}</summary>
              <UntrustedText text={command.stdoutTail} className="mt-1" />
            </details>
          )}
          {command.stderrTail && (
            <details open={command.exitCode !== null && command.exitCode !== undefined && command.exitCode !== 0}>
              <summary className="cursor-pointer text-xs font-semibold text-destructive">{t('Errors', { context: 'sandbox-command' })}</summary>
              <UntrustedText text={command.stderrTail} className="mt-1" />
            </details>
          )}
        </li>
      ))}
    </ol>
  );
};

const ConversationsTab = ({
  api,
  workspaceKey,
  buildConversationHref,
}: {
  api: SandboxesApi;
  workspaceKey: string;
  buildConversationHref?: (conversation: SandboxConversation) => string | null;
}) => {
  const { t } = useTranslate();
  const Link = useLinkComponent();
  const conversationsQuery = api.useSandboxConversationsQuery({ workspaceKey, limit: LOG_LIMIT });
  const columns: EntityListColumn<SandboxConversation>[] = [
    {
      id: 'id',
      header: t('Conversation'),
      width: 'content',
      cell: conversation => {
        const href = buildConversationHref?.(conversation) ?? null;
        return href ? (
          <Link href={href} className="flex items-center gap-1 font-mono text-[13px] text-primary-emphasis underline-offset-2 hover:underline">
            <Link2 className="size-3.5" />#{conversation.conversationId}
          </Link>
        ) : (
          <span className="font-mono text-[13px]">#{conversation.conversationId}</span>
        );
      },
    },
    { id: 'purpose', header: t('Purpose', { context: 'sandbox-conversation' }), width: '2fr', cell: conversation => conversation.purposeName ?? <EmptyValue /> },
    { id: 'state', header: t('State', { context: 'sandbox' }), width: 'content', cell: conversation => (conversation.runState ? <Badge variant="outline">{conversation.runState}</Badge> : <EmptyValue />) },
    { id: 'updated', header: t('Last change'), width: 'content', cell: conversation => <DateCell value={conversation.updatedAt} /> },
  ];
  return (
    <EntityListCard label={m('Conversations', { context: 'sandbox-tab' })} labelContext="sandbox-tab">
      <EntityListBody
        displayMode="table"
        columns={columns}
        items={conversationsQuery.data?.conversations?.elements ?? []}
        getRowKey={conversation => String(conversation.conversationId)}
        isLoading={conversationsQuery.isLoading}
        emptyMessage={t('No conversation used this workspace yet.')}
        loadingMessage={t('Loading the conversations')}
        error={conversationsQuery.isError ? errorText(conversationsQuery.error, t('The conversations could not be loaded.')) : undefined}
      />
    </EntityListCard>
  );
};

const EventsTab = ({ api, workspaceKey }: { api: SandboxesApi; workspaceKey: string }) => {
  const { t } = useTranslate();
  const eventsQuery = api.useSandboxSessionEventsQuery({ workspaceKey, limit: LOG_LIMIT });
  const events = eventsQuery.data?.sessionEvents?.elements ?? [];
  if (eventsQuery.isLoading) return <Skeleton className="h-40 w-full rounded-xl" />;
  if (eventsQuery.isError) return <StatusCallout tone="destructive" title={t('The session log could not be loaded.')} description={errorText(eventsQuery.error, '')} />;
  if (events.length === 0) return <p className="py-10 text-center text-[13px] text-muted-foreground">{t('The session log is empty.')}</p>;
  return (
    <ol className="surface space-y-3 rounded-2xl p-4">
      {events.map((event, index) => {
        const Icon = sessionEventIconOf(event.type);
        return (
          <li key={`${event.occurredAt ?? ''}-${index}`} className="flex gap-3 text-[13px]">
            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0">
              <p>
                <span className="font-mono font-semibold">{event.type}</span> · <DateCell value={event.occurredAt} />
              </p>
              {event.detail && <p className="break-words text-muted-foreground">{event.detail}</p>}
            </div>
          </li>
        );
      })}
    </ol>
  );
};

const AuditTab = ({ api, workspaceKey }: { api: SandboxesApi; workspaceKey: string }) => {
  const { t } = useTranslate();
  const auditQuery = api.useSandboxActionLogQuery({ workspaceKey, limit: LOG_LIMIT });
  const columns: EntityListColumn<SandboxActionLogEntry>[] = [
    { id: 'time', header: t('When', { context: 'sandbox-audit' }), width: 'content', cell: entry => <DateCell value={entry.changeHistory?.createdTime ?? null} /> },
    {
      id: 'action',
      header: t('Action', { context: 'sandbox-audit' }),
      width: 'content',
      cell: entry => (
        <span>
          {entry.action === 'KILL_JOB' && entry.jobId
            ? t('Kill job %id%', { context: 'sandbox-action', replacements: { id: entry.jobId } })
            : entry.action && SANDBOX_ACTION_LABELS[entry.action]
              ? t(SANDBOX_ACTION_LABELS[entry.action] ?? '', { context: 'sandbox-action' })
              : (entry.action ?? '—')}
        </span>
      ),
    },
    { id: 'outcome', header: t('Outcome', { context: 'sandbox-audit' }), width: 'content', cell: entry => <StatusBadge value={(entry.outcome ?? null) as SandboxActionOutcome | null} map={SANDBOX_ACTION_OUTCOME} /> },
    { id: 'actor', header: t('By', { context: 'sandbox-audit' }), width: 'content', cell: entry => (entry.actorAccountId ? `#${entry.actorAccountId}` : <EmptyValue />) },
    {
      id: 'reason',
      header: t('Reason', { context: 'sandbox-force' }),
      width: '2fr',
      cell: entry => (
        <span className="min-w-0 text-[13px]">
          {entry.forced && <Badge variant="destructive" className="mr-1">{t('Forced', { context: 'sandbox-audit' })}</Badge>}
          {entry.reason ?? (entry.forced ? '' : <EmptyValue />)}
          {entry.outcomeDetail && <span className="block truncate text-xs text-muted-foreground" title={entry.outcomeDetail}>{entry.outcomeDetail}</span>}
        </span>
      ),
    },
  ];
  return (
    <EntityListCard label={m('Audit', { context: 'sandbox-tab' })} labelContext="sandbox-tab">
      <EntityListBody
        displayMode="table"
        columns={columns}
        items={auditQuery.data?.actionLogEntries?.elements ?? []}
        getRowKey={entry => String(entry.id ?? `${entry.action}-${entry.changeHistory?.createdTime}`)}
        isLoading={auditQuery.isLoading}
        emptyMessage={t('No action on this workspace yet.')}
        loadingMessage={t('Loading the audit')}
        error={auditQuery.isError ? errorText(auditQuery.error, t('The audit could not be loaded.')) : undefined}
      />
    </EntityListCard>
  );
};

export interface SandboxDetailScreenProps {
  api: SandboxesApi;
  workspaceKey: string;
  /** The open tab (controlled, e.g. from the URL); without it the screen keeps it. */
  tab?: SandboxDetailTab;
  onTabChange?: (tab: SandboxDetailTab) => void;
  /** A link back (the list), drawn left of the title. */
  backLink?: ReactNode;
  headerActions?: ReactNode;
  /** The link of a conversation in the host's agent screens; `null` = no link. */
  buildConversationHref?: (conversation: SandboxConversation) => string | null;
  /** pdf.js of the host for the PDF preview. */
  loadPdfJs?: PdfJsLoader;
}

/**
 * One workspace: its facts (state, disk and memory against their limits, mount mode, runner pod, owner) and the tabs
 * Files (read only, the safe preview), Unsaved changes, Processes & jobs (kill), Commands (exit code, duration, output
 * tails), Conversations, Session log and Audit. Start, offload, end and reset (typed name) are audited and refused
 * while a turn runs on the space unless forced with a reason. Everything from the sandbox is shown as text.
 */
export const SandboxDetailScreen = ({
  api,
  workspaceKey,
  tab: controlledTab,
  onTabChange,
  backLink,
  headerActions,
  buildConversationHref,
  loadPdfJs,
}: SandboxDetailScreenProps) => {
  const { t } = useTranslate();
  const workspaceQuery = api.useSandboxQuery({ workspaceKey });
  const actions = useSandboxActions(api);
  const [ownTab, setOwnTab] = useState<SandboxDetailTab>('files');
  const tab = controlledTab ?? ownTab;
  const selectTab = (next: SandboxDetailTab) => {
    if (onTabChange) onTabChange(next);
    else setOwnTab(next);
  };
  const workspace = workspaceQuery.data?.workspace;

  if (workspaceQuery.isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-12 w-80" />
        <Skeleton className="h-32 w-full rounded-2xl" />
      </div>
    );
  }
  if (!workspace) {
    return (
      <div className="space-y-4">
        <PageHeader title={workspaceKey} leading={backLink} />
        <StatusCallout
          tone="destructive"
          title={t('This workspace was not found.')}
          description={workspaceQuery.isError ? errorText(workspaceQuery.error, t('The workspace could not be loaded.')) : undefined}
        />
      </div>
    );
  }

  const hasSession = workspace.state !== 'OFFLOADED';
  const busy = actions.isPending;
  return (
    <div className="space-y-6">
      <PageHeader
        leading={backLink}
        title={sandboxNameOf(workspace)}
        description={<span className="font-mono text-xs">{workspace.workspaceKey}</span>}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {headerActions}
            <Button type="button" variant="outline" onClick={() => void workspaceQuery.refetch?.()} disabled={workspaceQuery.isFetching}>
              <RefreshCw className={cn(workspaceQuery.isFetching && 'animate-spin')} />
              {t('Refresh')}
            </Button>
            <Button type="button" variant="outline" disabled={busy} onClick={() => void actions.run({ kind: 'start', workspace })}>
              <Play />
              {hasSession ? t('Warm up session') : t('Start session', { context: 'sandbox-action' })}
            </Button>
            <Button type="button" variant="outline" disabled={busy || !hasSession} onClick={() => void actions.run({ kind: 'offload', workspace })}>
              <Archive />
              {t('Offload', { context: 'sandbox-action' })}
            </Button>
            <Button type="button" variant="outline" disabled={busy || !hasSession} onClick={() => void actions.run({ kind: 'end', workspace })}>
              <Power />
              {t('End session', { context: 'sandbox-action' })}
            </Button>
            <Button
              type="button"
              variant="outline"
              className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
              disabled={busy}
              onClick={() => void actions.run({ kind: 'reset', workspace })}
            >
              <RotateCcw />
              {t('Reset workspace', { context: 'sandbox-action' })}
            </Button>
          </div>
        }
      />
      {workspace.hasLiveTurn && (
        <StatusCallout
          tone="warning"
          title={t('A turn is running on this space')}
          description={t('Actions now can break the agent’s work; each asks for a reason, which is kept in the audit.')}
        />
      )}
      <Card>
        <CardContent className="pt-6">
          <DefinitionList
            items={[
              { id: 'state', label: t('State', { context: 'sandbox' }), value: <StatusBadge value={workspace.state as SandboxState} map={SANDBOX_STATE} /> },
              { id: 'activity', label: t('Last activity'), value: <DateCell value={workspace.lastActivityAt} /> },
              { id: 'disk', label: t('Disk', { context: 'sandbox' }), value: <SandboxUsageBar used={workspace.diskUsedBytes} limit={workspace.diskQuotaBytes} /> },
              {
                id: 'memory',
                label: t('Memory', { context: 'sandbox' }),
                value: <SandboxUsageBar used={workspace.memoryBytes} limit={workspace.memoryLimitBytes} peak={workspace.memoryPeakBytes} />,
              },
              { id: 'mount', label: t('Mount', { context: 'sandbox' }), value: <SandboxMountMode workspace={workspace} /> },
              { id: 'pod', label: t('Runner', { context: 'sandbox' }), value: workspace.runnerPod ? <span className="font-mono text-xs">{workspace.runnerPod}</span> : null },
              { id: 'owner', label: t('Owner', { context: 'sandbox' }), value: workspace.ownerDisplayName },
              { id: 'jobs', label: t('Running jobs'), value: workspace.runningJobCount ?? null },
              { id: 'conversations', label: t('Conversations'), value: workspace.conversationCount ?? null },
            ]}
          />
        </CardContent>
      </Card>
      <section className="space-y-4">
        <SectionHeader
          title={t(SANDBOX_DETAIL_TABS.find(item => item.id === tab)?.label ?? '', { context: 'sandbox-tab' })}
          tabs={SANDBOX_DETAIL_TABS}
          activeTab={tab}
          onTabSelect={id => selectTab(id as SandboxDetailTab)}
          tabsLabel={t('Workspace views')}
        />
        {tab === 'files' && <FilesTab api={api} workspaceKey={workspaceKey} loadPdfJs={loadPdfJs} />}
        {tab === 'changes' && <ChangesTab api={api} workspaceKey={workspaceKey} />}
        {tab === 'processes' && (
          <ProcessesTab api={api} workspace={workspace} killDisabled={busy} onKill={jobId => void actions.run({ kind: 'kill', workspace, jobId })} />
        )}
        {tab === 'commands' && <CommandsTab api={api} workspaceKey={workspaceKey} />}
        {tab === 'conversations' && <ConversationsTab api={api} workspaceKey={workspaceKey} buildConversationHref={buildConversationHref} />}
        {tab === 'events' && <EventsTab api={api} workspaceKey={workspaceKey} />}
        {tab === 'audit' && <AuditTab api={api} workspaceKey={workspaceKey} />}
      </section>
      {actions.dialog}
    </div>
  );
};
