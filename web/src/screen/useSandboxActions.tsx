'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/contract';
import { type ReactNode, useCallback, useState } from 'react';

import { FormDialog } from '@mgamadeus/ddd-admin-ui/form';
import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { notify } from '@mgamadeus/ddd-admin-ui/logic';
import { useConfirm } from '@mgamadeus/ddd-admin-ui/logic';
import { Label } from '@mgamadeus/ddd-admin-ui/primitives';
import { Textarea } from '@mgamadeus/ddd-admin-ui/primitives';
import type { SandboxesApi } from '../api.ts';
import { isSandboxTurnRunningRefusal, sandboxNameOf, sandboxResetPhraseOf } from './format.ts';
import type { SandboxActionBody, SandboxActionResponse, SandboxWorkspace } from '../types.ts';

/** One action of the sandbox screens. */
export type SandboxActionRequest =
  | { kind: 'start' | 'offload' | 'end' | 'reset'; workspace: SandboxWorkspace }
  | { kind: 'kill'; workspace: SandboxWorkspace; jobId: string };

export interface SandboxActions {
  /** Asks (confirmation, typed name for a reset, a reason while a turn runs), runs it and reports the outcome. */
  run: (request: SandboxActionRequest) => Promise<boolean>;
  /** An action is running. */
  isPending: boolean;
  /** The "force with a reason" dialog; render it once in the screen. */
  dialog: ReactNode;
}

const MIN_REASON_LENGTH = 3;

/**
 * The actions of the sandbox screens: start, offload, end, reset (typed name) and kill a job. A running turn on the
 * space (`hasLiveTurn`, or the server's 409) asks for a reason and sends the action with `force`; the server audits it.
 */
export const useSandboxActions = (api: SandboxesApi): SandboxActions => {
  const { t } = useTranslate();
  const confirm = useConfirm();
  const [runAction, actionState] = api.useSandboxActionMutation();
  const [killJob, killState] = api.useKillSandboxJobMutation();
  const [forceRequest, setForceRequest] = useState<{ request: SandboxActionRequest; extra: SandboxActionBody } | null>(null);
  const [reason, setReason] = useState('');
  const [forcing, setForcing] = useState(false);

  const labelOf = useCallback(
    (request: SandboxActionRequest): string => {
      switch (request.kind) {
        case 'start':
          return t('Start session', { context: 'sandbox-action' });
        case 'offload':
          return t('Offload', { context: 'sandbox-action' });
        case 'end':
          return t('End session', { context: 'sandbox-action' });
        case 'reset':
          return t('Reset workspace', { context: 'sandbox-action' });
        case 'kill':
          return t('Kill job', { context: 'sandbox-action' });
      }
    },
    [t]
  );

  const send = useCallback(
    async (request: SandboxActionRequest, body: SandboxActionBody): Promise<SandboxActionResponse> =>
      request.kind === 'kill'
        ? killJob({ workspaceKey: request.workspace.workspaceKey, jobId: request.jobId, ...body }).unwrap()
        : runAction({ workspaceKey: request.workspace.workspaceKey, action: request.kind, ...body }).unwrap(),
    [killJob, runAction]
  );

  const report = useCallback(
    (request: SandboxActionRequest, response: SandboxActionResponse) => {
      const { actionResult } = response;
      const name = sandboxNameOf(request.workspace);
      if (actionResult.outcome !== 'SUCCEEDED') {
        notify.error(t('%action% did not run for %name%.', { replacements: { action: labelOf(request), name } }));
        return false;
      }
      const offload = actionResult.offloadResult;
      notify.success(
        offload
          ? t('%name% offloaded: %count% changes saved to the drive.', { replacements: { name, count: offload.committedChangeCount } })
          : t('%action%: done for %name%.', { replacements: { action: labelOf(request), name } })
      );
      return true;
    },
    [t, labelOf]
  );

  const attempt = useCallback(
    async (request: SandboxActionRequest, body: SandboxActionBody): Promise<boolean> => {
      try {
        return report(request, await send(request, body));
      } catch (error) {
        if (!body.force && isSandboxTurnRunningRefusal(error)) {
          setReason('');
          setForceRequest({ request, extra: body });
          return false;
        }
        notify.error(normalizeDddError(error, t('%action% failed.', { replacements: { action: labelOf(request) } })).message);
        return false;
      }
    },
    [send, report, t, labelOf]
  );

  const run = useCallback(
    async (request: SandboxActionRequest): Promise<boolean> => {
      const name = sandboxNameOf(request.workspace);
      const extra: SandboxActionBody = {};
      if (request.kind === 'reset') {
        const phrase = sandboxResetPhraseOf(request.workspace);
        const confirmed = await confirm({
          title: t('Reset the workspace of %name%?', { replacements: { name } }),
          description: t(
            'Everything on the runner is wiped: local files, installed packages, unsaved changes. Files in the drive stay. The agent starts from an empty workspace next time.'
          ),
          confirmLabel: t('Reset workspace', { context: 'sandbox-action' }),
          confirmVariant: 'destructive',
          requirePhrase: phrase,
        });
        if (!confirmed) return false;
        extra.confirmation = phrase;
      } else if (request.kind === 'offload' || request.kind === 'end' || request.kind === 'kill') {
        const jobId = request.kind === 'kill' ? request.jobId : '';
        const confirmed = await confirm({
          title:
            request.kind === 'offload'
              ? t('Offload %name%?', { replacements: { name } })
              : request.kind === 'end'
                ? t('End the session of %name%?', { replacements: { name } })
                : t('Kill the job %job%?', { replacements: { job: jobId } }),
          description:
            request.kind === 'offload'
              ? t('Unsaved changes are written to the drive, then the session is released. The workspace stays and starts again on the next command.')
              : request.kind === 'end'
                ? t('The session stops now, with every running command. The saved workspace stays; changes not yet in the drive can be lost — offload first to keep them.')
                : t('The job stops at once; its output so far stays in the command history.'),
          confirmLabel: labelOf(request),
          confirmVariant: request.kind === 'offload' ? 'default' : 'destructive',
        });
        if (!confirmed) return false;
      }
      if (request.workspace.hasLiveTurn) {
        setReason('');
        setForceRequest({ request, extra });
        return false;
      }
      return attempt(request, extra);
    },
    [confirm, t, labelOf, attempt]
  );

  const dialog = (
    <FormDialog
      open={forceRequest !== null}
      onOpenChange={open => !open && setForceRequest(null)}
      title={forceRequest ? t('%action% while a turn is running?', { replacements: { action: labelOf(forceRequest.request) } }) : ''}
      description={t(
        'An agent conversation of this space is working right now; the action can break its turn. Say why it must run now — the reason is kept in the audit.'
      )}
      submitLabel={t('Run anyway')}
      pendingLabel={t('Running…')}
      submitVariant="destructive"
      isPending={forcing}
      submitDisabled={reason.trim().length < MIN_REASON_LENGTH}
      onSubmit={async event => {
        event.preventDefault();
        if (!forceRequest) return;
        setForcing(true);
        try {
          const ok = await attempt(forceRequest.request, { ...forceRequest.extra, force: true, reason: reason.trim() });
          if (ok) setForceRequest(null);
        } finally {
          setForcing(false);
        }
      }}
    >
      <div className="space-y-2">
        <Label htmlFor="sandbox-force-reason">{t('Reason', { context: 'sandbox-force' })}</Label>
        <Textarea id="sandbox-force-reason" value={reason} onChange={event => setReason(event.target.value)} rows={3} maxLength={500} />
      </div>
    </FormDialog>
  );

  return { run, isPending: actionState.isLoading || killState.isLoading || forcing, dialog };
};
