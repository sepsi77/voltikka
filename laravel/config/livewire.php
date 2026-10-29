<?php

// Livewire merges this file over its own config one top-level key deep, so the
// whole temporary_file_upload array is repeated with the package defaults.
return [

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Voltikka has no file upload field. Filament pages, including the public
        // /admin/login page, still accept Livewire temporary uploads, and scanners use
        // them to store files on the /app/storage volume (60 × 12 MB per minute per IP
        // by default). Rejecting every file before storage closes that path. Change
        // this only when a real upload field is added.
        'rules' => ['prohibited'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
