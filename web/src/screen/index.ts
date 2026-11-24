// `@mgamadeus/ddd-agent-sandbox/screen`: the sandbox admin screens on the blocks of @mgamadeus/ddd-admin-ui — the
// workspace list, one workspace with its files (read only, the safe preview), unsaved changes, processes and jobs,
// commands, conversations, session log and audit, and the audited actions.
export {
  formatSandboxBytes,
  formatSandboxDuration,
  isSandboxTurnRunningRefusal,
  SANDBOX_ACTION_LABEL_CONTEXT,
  SANDBOX_ACTION_LABELS,
  SANDBOX_ACTION_OUTCOME,
  SANDBOX_CHANGE_LABEL_CONTEXT,
  SANDBOX_CHANGE_LABELS,
  SANDBOX_COMMAND_STATE,
  SANDBOX_STATE,
  SANDBOX_TEXT_PREVIEW_BYTES,
  sandboxCommandStateOf,
  sandboxNameOf,
  sandboxParentPathOf,
  sandboxPathCrumbs,
  sandboxPreviewKindOf,
  sandboxResetPhraseOf,
  usagePercentOf,
} from './format.ts';
export type { SandboxCommandState, SandboxPreviewKind } from './format.ts';
export { SandboxActionsMenu, SandboxesScreen, SandboxMountMode, SandboxUsageBar } from './SandboxesScreen.tsx';
export type { SandboxesScreenProps } from './SandboxesScreen.tsx';
export { SANDBOX_DETAIL_TABS, SandboxDetailScreen } from './SandboxDetailScreen.tsx';
export type { SandboxDetailScreenProps, SandboxDetailTab } from './SandboxDetailScreen.tsx';
export { SandboxFilePreview } from './SandboxFilePreview.tsx';
export type { SandboxFilePreviewProps } from './SandboxFilePreview.tsx';
export { useSandboxActions } from './useSandboxActions.tsx';
export type { SandboxActionRequest, SandboxActions } from './useSandboxActions.tsx';
