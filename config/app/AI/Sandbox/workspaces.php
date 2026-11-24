<?php

declare(strict_types=1);

/**
 * Agent sandbox module: where workspaces live between sessions and how they are saved.
 *
 * A config file replaces the module's default file as a whole: keep every key.
 */
return [
    // the workspace store: 'manifest' (built in: manifest and contents by sha256 in an object store) or the name of
    // another registered SandboxWorkspaceStore
    'store' => 'manifest',
    // the object store of the manifest store (config/app/Common/ObjectStorage.php, OBJECT_STORAGE_<NAME>_*)
    'objectStore' => 'sandbox-workspaces',
    // the key prefix inside that object store
    'keyPrefix' => 'sandbox-workspaces/',
    // seconds without a call after which a session is saved and released
    'idleReleaseSeconds' => 900,
    // a file larger than this stays in the session only (named "NOT SAVED, too large")
    'maxSyncedFileBytes' => 104857600,
    // calls answer at once and a flush process uploads to the store (app:agent-sandbox:sessions --flush)
    'uploadInBackground' => true,
    // bytes of saved files a workspace keeps before the expiry removes the oldest scratch
    'storedWorkspaceQuotaBytes' => 3600000000,
    // the state note of a conversation's first call: characters of NOTES.md, recently changed files listed
    'notesPreviewCharacters' => 4000,
    'recentFilesInStateNote' => 10,
    // the content cache of the manifest store (contents are named by their hash, so a cached one never goes stale)
    'contentCacheSeconds' => 259200,
    'contentCacheMaxBytes' => 1048576,
    // the preloaded content (app:agent-sandbox:seed): a folder of the project, read-only below $SANDBOX_PRELOAD in every
    // session; null = none. A file `start.sh` at its top runs once at the start of every session
    'preloadDirectory' => 'resources/Sandbox/preload',
];
