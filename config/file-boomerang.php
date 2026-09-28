<?php

return [
    'enabled' => env('FILE_BOOMERANG_ENABLED', false),

    'mailbox' => [
        'disk' => env('FILE_BOOMERANG_DISK'),
        'bucket' => env('FILE_BOOMERANG_BUCKET'),
        'endpoint' => env('FILE_BOOMERANG_ENDPOINT'),
        'key' => env('FILE_BOOMERANG_ACCESS_KEY_ID'),
        'secret' => env('FILE_BOOMERANG_SECRET_ACCESS_KEY'),
        'region' => env('FILE_BOOMERANG_REGION', 'auto'),
        'use_path_style_endpoint' => (bool) env('FILE_BOOMERANG_PATH_STYLE', false),
        'prefix' => env('FILE_BOOMERANG_PREFIX', 'file-boomerang'),
    ],

    'paths' => [
        'content',
        'users',
        'resources/addons',
        'resources/blueprints',
        'resources/fieldsets',
        'resources/forms',
        'resources/users',
        'resources/preferences.yaml',
        'resources/sites.yaml',
        'storage/forms',
    ],

    'local_asset_containers' => true,

    'exclude' => [
        '.DS_Store',
        '*/.DS_Store',
    ],

    'max_file_size' => 50 * 1024 * 1024,

    'debounce' => (int) env('FILE_BOOMERANG_DEBOUNCE', 120),

    'manifest' => storage_path('framework/file-boomerang.json'),

    'catch_up' => [
        'enabled' => true,
        'interval' => 15,
    ],

    'github' => [
        'repository' => env('FILE_BOOMERANG_GITHUB_REPOSITORY'),
        'branch' => env('FILE_BOOMERANG_GITHUB_BRANCH', 'main'),
        'token' => env('FILE_BOOMERANG_GITHUB_TOKEN'),
        'event' => 'file-boomerang',
    ],

    'landing' => [
        'commit_message' => 'Update content from the control panel',
        'author' => [
            'name' => env('FILE_BOOMERANG_AUTHOR_NAME', 'Statamic'),
            'email' => env('FILE_BOOMERANG_AUTHOR_EMAIL', 'statamic@users.noreply.github.com'),
        ],
        'workflows' => [],
        'deploy_hook' => env('FILE_BOOMERANG_DEPLOY_HOOK'),
        'redispatch_after' => 30,
        'blob_grace' => 60,
    ],

    'queue' => [
        'connection' => env('FILE_BOOMERANG_QUEUE_CONNECTION'),
        'name' => env('FILE_BOOMERANG_QUEUE'),
    ],
];
