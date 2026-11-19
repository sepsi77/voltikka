<?php

/*
 * Staged external API imports (tasks/staged-api-imports, app/Services/ImportPayload/AGENTS.md).
 * The fetch step saves each run's raw responses under this directory; the store step reads them.
 * Production keeps it on the shared voltikka-storage volume, so every container that runs a fetch
 * or store activity sees the same files. Tests point it at a temporary directory.
 */
return [
    'path' => storage_path('app/import-payloads'),
];
