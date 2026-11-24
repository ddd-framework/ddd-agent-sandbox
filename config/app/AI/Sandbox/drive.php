<?php

declare(strict_types=1);

/**
 * Agent sandbox module: the drive store (`AI.Sandbox.workspaces.store` = drive, beside mgamadeus/ddd-clouddrive and
 * mgamadeus/ddd-agents). The workspace of an agent space IS the working copy of its own drive; further spaces (the
 * library) are mounted read-only below drivespaces/.
 *
 * A config file replaces the module's default file as a whole: keep every key.
 */
return [
    // own-drive files above this size stay not local in the session (`drive get <path>` fetches one)
    'thresholdBytes' => 20 * 1024 * 1024,
    // tree plus content cache of the drive on the runner volume
    'mirrorBudgetBytes' => 1536 * 1024 * 1024,
    // files of a mounted space up to this size get a URL (the runner keeps small text files of a mount local)
    'mountTextMaxBytes' => 256 * 1024,
    // never synced (gitignore style, besides the runner's fixed .runner, drivespaces, mounts, .drive-index)
    'ignore' => ['.venv', 'node_modules', '__pycache__', '.cache', '.git', '.ipynb_checkpoints', 'lost+found'],
    // pauses of the in-session `drive` CLI answered per command
    'maxRequestRounds' => 20,
    // the title of the library space the seed creates for the preload folder (mounted as drivespaces/library/)
    'libraryTitle' => 'Library',
];
