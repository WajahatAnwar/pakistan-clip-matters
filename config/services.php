<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'dropbox' => [
        'client_id' => env('DROPBOX_APP_KEY'),
        'client_secret' => env('DROPBOX_APP_SECRET'),
        'redirect' => env('DROPBOX_REDIRECT_URI'),
    ],

    'google_cloud_speech' => [
        'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
        'location' => env('GOOGLE_CLOUD_LOCATION', 'global'),
        'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'embedding' => [
        'url' => env('EMBEDDING_SERVICE_URL', 'https://web-production-935af.up.railway.app'),
        'api_key' => env('EMBEDDING_API_KEY', ''),
    ],

];
