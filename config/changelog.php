<?php

// config for Filament/Changelog
return [

    /*
    |--------------------------------------------------------------------------
    | Database table
    |--------------------------------------------------------------------------
    |
    | Table used to store changelog entries when using the database source.
    |
    */
    'table' => 'changelog_entries',

    /*
    |--------------------------------------------------------------------------
    | Reader source
    |--------------------------------------------------------------------------
    |
    | Where the reader page reads from:
    |   - 'database' : the changelog_entries table (edit via the panel)
    |   - 'file'     : parses the CHANGELOG.md file below live on every visit,
    |                  so it always reflects your project's real file — never a
    |                  stale copy.
    |
    */
    'source' => env('CHANGELOG_SOURCE', 'file'),

    /*
    |--------------------------------------------------------------------------
    | CHANGELOG.md path
    |--------------------------------------------------------------------------
    |
    | Path to your project's changelog file, used by the import / export
    | commands and by the 'file' reader source. A relative path resolves from
    | the app base path; an absolute path (starting with "/") is used as-is —
    | so you can point it at your own project anywhere on disk.
    |
    */
    'file' => env('CHANGELOG_FILE', 'CHANGELOG.md'),

    /*
    |--------------------------------------------------------------------------
    | Remote source (URL)
    |--------------------------------------------------------------------------
    |
    | When "file" is an http(s) URL (e.g. a GitHub raw CHANGELOG.md), it is
    | fetched and cached for this many seconds (0 disables the cache) with the
    | given request timeout.
    |
    */
    'remote_cache_ttl' => env('CHANGELOG_REMOTE_CACHE_TTL', 300),

    'remote_timeout' => 5,

    /*
    |--------------------------------------------------------------------------
    | Multi-project support
    |--------------------------------------------------------------------------
    |
    | When true, entries are scoped by a "project" column so a single app can
    | track changelogs for several projects. When false the column is still
    | present but ignored in the UI.
    |
    */
    'multi_project' => false,

    /*
    |--------------------------------------------------------------------------
    | Authorization policy
    |--------------------------------------------------------------------------
    |
    | Policy class bound to the ChangelogEntry model. Because the model lives in
    | this package's namespace, Laravel's auto-discovery cannot find a policy
    | generated under App\Policies (e.g. by Filament Shield), so the plugin
    | binds it explicitly. Leave null to auto-detect App\Policies\ChangelogEntryPolicy.
    |
    */
    'policy' => null,

    /*
    |--------------------------------------------------------------------------
    | Change types (Keep a Changelog)
    |--------------------------------------------------------------------------
    |
    | The canonical Keep a Changelog groups. Order defines the render order in
    | the generated CHANGELOG.md.
    |
    */
    'types' => [
        'added',
        'changed',
        'deprecated',
        'removed',
        'fixed',
        'security',
    ],

    /*
    |--------------------------------------------------------------------------
    | Date format
    |--------------------------------------------------------------------------
    |
    | PHP date() format used for the release-date badge on the reader page.
    |
    */
    'date_format' => 'd M Y',

    /*
    |--------------------------------------------------------------------------
    | Reader page
    |--------------------------------------------------------------------------
    |
    | Behaviour of the read-only changelog reader:
    |   - per_page             : version cards revealed per infinite-scroll step
    |   - searchable           : show the live search box
    |   - filterable_by_version: show the version filter select
    |
    */
    'reader' => [
        'per_page' => 8,
        'searchable' => true,
        'filterable_by_version' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | The plugin registers two navigation items in the same group:
    |   - "page": the read-only, nicely formatted changelog reader
    |   - "resource": the management table (create / edit / import / export)
    |
    | Navigation labels come from the translation files (resources/lang/*),
    | not from here, so they follow the app locale (en, pt_PT, pt_BR).
    |
    */
    'navigation' => [
        'group' => 'Documentation',

        'page' => [
            'slug' => 'changelog',
            'sort' => 90,
        ],

        'resource' => [
            'slug' => 'changelog/manage',
            'sort' => 91,
        ],
    ],
];
