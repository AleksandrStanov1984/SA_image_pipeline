<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cron / Inbox settings
    |--------------------------------------------------------------------------
    */

    'inbox_dir' => 'image-inbox',
    'inbox_expected_prefix' => 'assets',
    'public_root_rel' => '',
    'reports_dir' => 'reports',
    'manifest_path' => 'image-inbox/.manifest.json',

    'cron' => [
        'move_strategy' => 'copy_then_delete',
        'allow_ext' => ['jpg','jpeg','png','webp'],
        'max_source_mb' => 40,
        'ignore_hidden' => true,
        'fingerprint' => 'size_mtime',
    ],

    'default_optimize_args' => [
        'path' => 'assets',
        'retina' => true,
        'clean_names' => false,
        'hash_names' => false,
        'purge_webp' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiles
    |--------------------------------------------------------------------------
    */

    'profiles' => [

        /*
        |--------------------------------------------------------------------------
        | OG Images (STRICT JPG 1200x630)
        |--------------------------------------------------------------------------
        */
        'og' => [
            'match' => 'assets/og/*',
            'format' => 'jpg',
            'exact' => ['w' => 1200, 'h' => 630, 'mode' => 'cover'],
            'quality' => 82,
            'keep_source' => true,
        ],

        /*
        |--------------------------------------------------------------------------
        | SVG / Static Assets (NO OPTIMIZATION)
        |--------------------------------------------------------------------------
        */
        'icons' => [
            'match' => 'assets/icons/**',
            'skip' => true,
        ],

        'brand' => [
            'match' => 'assets/brand/**',
            'skip' => true,
        ],

        'author_icons' => [
            'match' => 'assets/author/icon/**',
            'skip' => true,
        ],

        /*
        |--------------------------------------------------------------------------
        | HERO / SECTIONS
        |--------------------------------------------------------------------------
        */
        'hero_api' => [
            'match' => 'assets/hero/**',
            'sizes' => [2000, 3000],
            'quality' => 82,
            'keep_source' => false,
        ],

        'section_hero' => [
            'match' => 'assets/components/sections/**/hero/*',
            'sizes' => [1800, 2800],
            'quality' => 82,
            'keep_source' => false,
        ],

        'chapters' => [
            'match' => 'assets/components/sections/chapters/**',
            'sizes' => [1600, 2400],
            'quality' => 80,
            'keep_source' => false,
        ],

        'reasons' => [
            'match' => 'assets/components/sections/**/reasons/*',
            'sizes' => [1600, 2400],
            'quality' => 80,
            'keep_source' => false,
        ],

        'gallery' => [
            'match' => 'assets/components/sections/**/gallery/*',
            'sizes' => [1600, 2400],
            'quality' => 80,
            'keep_source' => false,
        ],

        'footer' => [
            'match' => 'assets/footer/**',
            'sizes' => [1200, 1800],
            'quality' => 78,
            'keep_source' => false,
        ],

        'fallbacks' => [
            'match' => 'assets/images/**',
            'sizes' => [1200, 1800],
            'quality' => 78,
            'keep_source' => false,
        ],

        'author_profile' => [
            'match' => 'assets/author/profile/**',
            'sizes' => [1400, 2000],
            'quality' => 82,
            'keep_source' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Default (LAST)
        |--------------------------------------------------------------------------
        */
        'default' => [
            'match' => 'assets/**/*',
            'sizes' => [1400, 2000],
            'quality' => 78,
            'keep_source' => false,
        ],
    ],
];
