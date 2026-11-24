// The payloads of mgamadeus/ddd-agent-sandbox's `SandboxesControllerTrait`. Date-times are the server's
// offsetless UTC strings (`Y-m-d H:i:s`); sets arrive as `{ elements: [...] }`.

/** `SandboxWorkspace::STATE_*`. */
export type SandboxState = 'RUNNING' | 'IDLE' | 'OFFLOADED';

/** `SandboxWorkspace::MOUNT_MODE_*`. */
export type SandboxMountMode = 'FUSE' | 'PLAIN';

export interface SandboxElements<TItem> {
  elements?: TItem[] | null;
}

/** One workspace (one per agent space). */
export interface SandboxWorkspace {
  workspaceKey: string;
  agentSpaceId?: number | null;
  /** The space's name as the drive shows it; `null` = none (the key is shown). */
  displayName?: string | null;
  state: SandboxState | string;
  lastActivityAt?: string | null;
  diskUsedBytes?: number | null;
  diskQuotaBytes?: number | null;
  memoryBytes?: number | null;
  memoryPeakBytes?: number | null;
  memoryLimitBytes?: number | null;
  mountMode?: SandboxMountMode | string | null;
  mountModeReason?: string | null;
  runnerPod?: string | null;
  ownerDisplayName?: string | null;
  runningJobCount?: number | null;
  conversationCount?: number | null;
  /** A conversation of the space has a running turn: actions need `force` + a reason. */
  hasLiveTurn?: boolean;
}

export interface SandboxWorkspacesResponse {
  /** `false`: the host has no `SandboxAdministration`; the screen says so. */
  isConfigured: boolean;
  workspaces: SandboxElements<SandboxWorkspace>;
}

export interface SandboxWorkspaceResponse {
  workspace: SandboxWorkspace;
}

export type SandboxFileType = 'FILE' | 'DIRECTORY' | 'SYMLINK';

export interface SandboxFileEntry {
  name: string;
  /** Relative to the workspace root (`reports/q1.csv`). */
  path: string;
  type: SandboxFileType | string;
  sizeBytes?: number | null;
  modifiedAt?: string | null;
}

export interface SandboxFilesResponse {
  directoryPath: string;
  entries: SandboxElements<SandboxFileEntry>;
}

export interface SandboxDriveChange {
  path: string;
  changeType: 'ADDED' | 'MODIFIED' | 'DELETED' | 'RENAMED' | string;
  previousPath?: string | null;
  sizeBytes?: number | null;
}

export interface SandboxDriveStatus {
  isDriveWorkspace: boolean;
  pendingChangeCount: number;
  changes: SandboxElements<SandboxDriveChange>;
  lastCommittedAt?: string | null;
}

export interface SandboxDriveStatusResponse {
  driveStatus: SandboxDriveStatus;
}

export interface SandboxProcess {
  pid: number;
  command: string;
  startedAt?: string | null;
  cpuSeconds?: number | null;
  rssBytes?: number | null;
  jobId?: string | null;
}

export interface SandboxProcessesResponse {
  processes: SandboxElements<SandboxProcess>;
}

export interface SandboxCommand {
  seq: number;
  command: string;
  startedAt?: string | null;
  durationMs?: number | null;
  exitCode?: number | null;
  stdoutTail?: string | null;
  stderrTail?: string | null;
  isTruncated: boolean;
  jobId?: string | null;
  /**
   * `RUNNING` | `EXITED` | `KILLED` | `TIMED_OUT`; null or absent = unknown — never read as
   * running.
   */
  status?: SandboxCommandStatus | null;
}

/** `SandboxCommand.status`. */
export type SandboxCommandStatus = 'RUNNING' | 'EXITED' | 'KILLED' | 'TIMED_OUT';

export interface SandboxCommandsResponse {
  commands: SandboxElements<SandboxCommand>;
}

export interface SandboxSessionEvent {
  occurredAt?: string | null;
  /** `MEMORY_EXCEEDED`, `SECCOMP_DENIED`, `TIMEOUT`, … */
  type: string;
  detail?: string | null;
}

export interface SandboxSessionEventsResponse {
  sessionEvents: SandboxElements<SandboxSessionEvent>;
}

export interface SandboxConversation {
  conversationId: number;
  accountId?: number | null;
  runState?: string | null;
  purposeName?: string | null;
  createdAt?: string | null;
  updatedAt?: string | null;
}

export interface SandboxConversationsResponse {
  conversations: SandboxElements<SandboxConversation>;
}

/** `SandboxActionLogEntry::ACTION_*`. */
export type SandboxAction = 'START' | 'OFFLOAD' | 'KILL_JOB' | 'END' | 'RESET';

/** `SandboxActionLogEntry::OUTCOME_*`. */
export type SandboxActionOutcome = 'SUCCEEDED' | 'REFUSED' | 'FAILED';

export interface SandboxActionLogEntry {
  id?: number | null;
  workspaceKey?: string | null;
  agentSpaceId?: number | null;
  action?: SandboxAction | string | null;
  jobId?: string | null;
  actorAccountId?: number | null;
  forced?: boolean | null;
  reason?: string | null;
  outcome?: SandboxActionOutcome | string | null;
  outcomeDetail?: string | null;
  /** When the action ran (the row's change history). */
  changeHistory?: { createdTime?: string | null; modifiedTime?: string | null } | null;
}

export interface SandboxActionLogEntriesResponse {
  actionLogEntries: SandboxElements<SandboxActionLogEntry>;
}

export interface SandboxOffloadResult {
  committedChangeCount: number;
  wasSessionReleased: boolean;
  detail?: string | null;
}

export interface SandboxActionResult {
  action: SandboxAction | string;
  workspaceKey: string;
  outcome: SandboxActionOutcome | string;
  offloadResult?: SandboxOffloadResult | null;
  actionLogEntryId?: number | null;
}

export interface SandboxActionResponse {
  actionResult: SandboxActionResult;
}

export interface SandboxKeyArg {
  workspaceKey: string;
}

export interface SandboxLimitArg extends SandboxKeyArg {
  limit?: number;
}

export interface SandboxFilesArg extends SandboxKeyArg {
  /** `''` = the root. */
  path?: string;
}

export interface SandboxFileContentArg extends SandboxKeyArg {
  path: string;
  disposition: 'inline' | 'attachment';
}

/**
 * One file's bytes, in the shape of every file download (`{ blob, filename, contentType }`, as ddd-web's
 * `blobResponseHandler`): a host whose store ignores `payload.blob` needs no other rule. The bytes never enter the
 * cache (a mutation, not a query).
 */
export interface SandboxFileContent {
  blob: Blob;
  /** From `Content-Disposition`; null without one. */
  filename: string | null;
  contentType: string;
  /** The server cut an inline text preview (`X-Content-Truncated: true`). */
  isTruncated: boolean;
}

/** The body of every action: `force` with a `reason` while a turn runs; `confirmation` (the name) for a reset. */
export interface SandboxActionBody {
  force?: boolean;
  reason?: string;
  confirmation?: string;
}

export interface SandboxActionArg extends SandboxKeyArg, SandboxActionBody {
  action: 'start' | 'offload' | 'end' | 'reset';
}

export interface SandboxKillArg extends SandboxKeyArg, SandboxActionBody {
  jobId: string;
}
