'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/contract';
import { Activity, Archive, MoreHorizontal, Play, Power, RefreshCw } from 'lucide-react';
import { type ReactNode, useMemo, useState } from 'react';

import { m } from '@mgamadeus/ddd-admin-ui/i18n';
import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { DateCell } from '@mgamadeus/ddd-admin-ui/list';
import { EmptyValue } from '@mgamadeus/ddd-admin-ui/list';
import { EntityListBody } from '@mgamadeus/ddd-admin-ui/list';
import { EntityListCard } from '@mgamadeus/ddd-admin-ui/list';
import type { EntityListColumn } from '@mgamadeus/ddd-admin-ui/list';
import { EntityListToolbar } from '@mgamadeus/ddd-admin-ui/list';
import { StatusBadge } from '@mgamadeus/ddd-admin-ui/list';
import { PageHeader } from '@mgamadeus/ddd-admin-ui/page';
import { StatusCallout } from '@mgamadeus/ddd-admin-ui/page';
import { Badge } from '@mgamadeus/ddd-admin-ui/primitives';
import { Button } from '@mgamadeus/ddd-admin-ui/primitives';
import { cn } from '@mgamadeus/ddd-admin-ui/primitives';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@mgamadeus/ddd-admin-ui/primitives';
import type { SandboxesApi } from '../api.ts';
import { formatSandboxBytes, SANDBOX_STATE, sandboxNameOf, usagePercentOf } from './format.ts';
import type { SandboxState, SandboxWorkspace } from '../types.ts';
import { useSandboxActions } from './useSandboxActions.tsx';

/** A used / limit pair as a thin bar with its numbers (no bar without a limit). */
export const SandboxUsageBar = ({ used, limit, peak }: { used: number | null | undefined; limit: number | null | undefined; peak?: number | null }) => {
  const { t } = useTranslate();
  const percent = usagePercentOf(used, limit);
  const peakPercent = usagePercentOf(peak, limit);
  if (used === null || used === undefined) return <EmptyValue />;
  return (
    <div className="min-w-28 space-y-1">
      <p className="text-[12.5px] tabular-nums">
        {formatSandboxBytes(used)}
        {limit ? <span className="text-muted-foreground"> / {formatSandboxBytes(limit)}</span> : null}
        {peak !== undefined && peak !== null && (
          <span className="block text-xs text-muted-foreground">{t('Peak %size%', { replacements: { size: formatSandboxBytes(peak) } })}</span>
        )}
      </p>
      {percent !== null && (
        <div className="relative h-1.5 w-full overflow-hidden rounded-full bg-muted" role="presentation">
          <div
            className={cn('h-full rounded-full', percent >= 90 ? 'bg-destructive' : percent >= 75 ? 'bg-warning' : 'bg-primary')}
            style={{ width: `${percent}%` }}
          />
          {peakPercent !== null && peakPercent > percent && (
            <div className="absolute top-0 h-full w-0.5 bg-foreground/50" style={{ left: `calc(${peakPercent}% - 1px)` }} />
          )}
        </div>
      )}
    </div>
  );
};

/** The mount mode and why (FUSE: the drive is mounted; PLAIN: files are copied). */
export const SandboxMountMode = ({ workspace }: { workspace: SandboxWorkspace }) => {
  if (!workspace.mountMode) return <EmptyValue />;
  return (
    <span className="text-[12.5px]">
      <Badge variant="outline" className="font-mono">
        {workspace.mountMode}
      </Badge>
      {workspace.mountModeReason && <span className="mt-0.5 block max-w-56 truncate text-xs text-muted-foreground" title={workspace.mountModeReason}>{workspace.mountModeReason}</span>}
    </span>
  );
};

/** The actions of one workspace as a menu (start, offload, end). */
export const SandboxActionsMenu = ({
  workspace,
  onAction,
  disabled = false,
}: {
  workspace: SandboxWorkspace;
  onAction: (kind: 'start' | 'offload' | 'end') => void;
  disabled?: boolean;
}) => {
  const { t } = useTranslate();
  const hasSession = workspace.state !== 'OFFLOADED';
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          data-row-action-ignore
          disabled={disabled}
          aria-label={t('Actions for %name%', { replacements: { name: sandboxNameOf(workspace) } })}
        >
          <MoreHorizontal />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        <DropdownMenuItem onSelect={() => onAction('start')}>
          <Play />
          {hasSession ? t('Warm up session') : t('Start session', { context: 'sandbox-action' })}
        </DropdownMenuItem>
        <DropdownMenuItem disabled={!hasSession} onSelect={() => onAction('offload')}>
          <Archive />
          {t('Offload', { context: 'sandbox-action' })}
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem variant="destructive" disabled={!hasSession} onSelect={() => onAction('end')}>
          <Power />
          {t('End session', { context: 'sandbox-action' })}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

export interface SandboxesScreenProps {
  /** `createSandboxesApi(…)` of the host. */
  api: SandboxesApi;
  /** Default "Sandboxes". */
  title?: ReactNode;
  description?: ReactNode;
  headerActions?: ReactNode;
  /** Opens a workspace's details (the host's route, e.g. `router.push(…)`). */
  onOpenWorkspace?: (workspaceKey: string) => void;
}

const SEARCH_KEYS: readonly (keyof SandboxWorkspace)[] = ['displayName', 'workspaceKey', 'ownerDisplayName', 'runnerPod'];

/**
 * Every agent workspace of the host (one per agent space): state, last activity, disk and memory against their limits,
 * mount mode, runner pod, owner and conversations; start, offload and end per row (audited, refused while a turn runs
 * unless forced with a reason). Needs the Composer package mgamadeus/ddd-agent-sandbox (`SandboxesControllerTrait`).
 */
export const SandboxesScreen = ({ api, title, description, headerActions, onOpenWorkspace }: SandboxesScreenProps) => {
  const { t } = useTranslate();
  const listQuery = api.useSandboxesListQuery();
  const actions = useSandboxActions(api);
  const [search, setSearch] = useState('');
  const workspaces = useMemo(() => listQuery.data?.workspaces?.elements ?? [], [listQuery.data]);
  const needle = search.trim().toLocaleLowerCase();
  const shown = useMemo(
    () => (needle === '' ? workspaces : workspaces.filter(workspace => SEARCH_KEYS.some(key => String(workspace[key] ?? '').toLocaleLowerCase().includes(needle)))),
    [workspaces, needle]
  );
  const runningCount = workspaces.filter(workspace => workspace.state === 'RUNNING').length;

  const columns: EntityListColumn<SandboxWorkspace>[] = [
    {
      id: 'name',
      header: t('Workspace', { context: 'sandbox' }),
      width: '2fr',
      cell: workspace => (
        <span className="min-w-0">
          <span className="block truncate font-semibold">{sandboxNameOf(workspace)}</span>
          <span className="block truncate font-mono text-xs text-muted-foreground">{workspace.workspaceKey}</span>
        </span>
      ),
    },
    {
      id: 'state',
      header: t('State', { context: 'sandbox' }),
      width: 'content',
      cell: workspace => (
        <span className="flex flex-col items-start gap-1">
          <StatusBadge value={workspace.state as SandboxState} map={SANDBOX_STATE} />
          {workspace.hasLiveTurn && (
            <span className="flex items-center gap-1 text-xs text-warning">
              <Activity className="size-3" />
              {t('Turn running')}
            </span>
          )}
        </span>
      ),
    },
    { id: 'activity', header: t('Last activity'), width: 'content', cell: workspace => <DateCell value={workspace.lastActivityAt} /> },
    { id: 'disk', header: t('Disk', { context: 'sandbox' }), cell: workspace => <SandboxUsageBar used={workspace.diskUsedBytes} limit={workspace.diskQuotaBytes} /> },
    {
      id: 'memory',
      header: t('Memory', { context: 'sandbox' }),
      cell: workspace => <SandboxUsageBar used={workspace.memoryBytes} limit={workspace.memoryLimitBytes} peak={workspace.memoryPeakBytes} />,
    },
    { id: 'mount', header: t('Mount', { context: 'sandbox' }), cell: workspace => <SandboxMountMode workspace={workspace} /> },
    {
      id: 'pod',
      header: t('Runner', { context: 'sandbox' }),
      cell: workspace => (workspace.runnerPod ? <span className="font-mono text-xs">{workspace.runnerPod}</span> : <EmptyValue />),
    },
    { id: 'owner', header: t('Owner', { context: 'sandbox' }), cell: workspace => workspace.ownerDisplayName ?? <EmptyValue /> },
    {
      id: 'conversations',
      header: t('Conversations'),
      width: 'content',
      align: 'end',
      cell: workspace => <span className="tabular-nums">{workspace.conversationCount ?? '—'}</span>,
    },
    {
      id: 'actions',
      header: <span className="sr-only">{t('Actions', { context: 'table-column' })}</span>,
      width: 'content',
      pin: 'end',
      cell: workspace => (
        <SandboxActionsMenu workspace={workspace} disabled={actions.isPending} onAction={kind => void actions.run({ kind, workspace })} />
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        title={title ?? t('Sandboxes')}
        description={
          description ??
          t('The agents’ workspaces: one per agent space. Files and command output are shown as untrusted text, never run.')
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {headerActions}
            <Button type="button" variant="outline" onClick={() => void listQuery.refetch?.()} disabled={listQuery.isFetching}>
              <RefreshCw className={cn(listQuery.isFetching && 'animate-spin')} />
              {t('Refresh')}
            </Button>
          </div>
        }
      />
      {listQuery.data && !listQuery.data.isConfigured ? (
        <StatusCallout
          tone="info"
          title={t('Sandboxes are not set up')}
          description={t('No sandbox runner is configured in this application (AI.Sandbox.runner.host), so there is nothing to show.')}
        />
      ) : (
        <EntityListCard
          label={m('Sandboxes')}
          isRefetching={listQuery.isFetching && !listQuery.isLoading}
          toolbar={
            <EntityListToolbar
              search={{ value: search, onChange: setSearch, placeholder: t('Search name, key, owner or runner…'), 'aria-label': t('Search sandboxes') }}
              trailing={
                workspaces.length > 0 ? (
                  <span className="text-[12.5px] font-bold text-muted-foreground">
                    {t('{count, plural, one {# running} other {# running}}', { count: runningCount })} ·{' '}
                    {t('{count, plural, one {# workspace} other {# workspaces}}', { count: workspaces.length })}
                  </span>
                ) : undefined
              }
            />
          }
        >
          <EntityListBody
            displayMode="table"
            columns={columns}
            items={shown}
            getRowKey={workspace => workspace.workspaceKey}
            rowAction={onOpenWorkspace ? workspace => () => onOpenWorkspace(workspace.workspaceKey) : undefined}
            isLoading={listQuery.isLoading}
            emptyMessage={t('No workspace yet: a workspace appears with the first command of an agent space.')}
            noResultsMessage={t('No workspace matches the search.')}
            isSearchActive={needle !== ''}
            onClearSearch={() => setSearch('')}
            loadingMessage={t('Loading the sandboxes')}
            error={listQuery.isError ? normalizeDddError(listQuery.error, t('The sandboxes could not be loaded.')).message : undefined}
          />
        </EntityListCard>
      )}
      {actions.dialog}
    </div>
  );
};
