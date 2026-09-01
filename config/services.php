<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
    | Firebase Cloud Messaging. The service account drives the server side;
    | the `web` block is handed to the browser so it can ask Firebase for a
    | registration token. With either half missing, push quietly does nothing.
    */

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'client_email' => env('FCM_CLIENT_EMAIL'),
        'private_key' => env('FCM_PRIVATE_KEY'),
        'web' => [
            'api_key' => env('FIREBASE_API_KEY'),
            'auth_domain' => env('FIREBASE_AUTH_DOMAIN'),
            'project_id' => env('FIREBASE_PROJECT_ID', env('FCM_PROJECT_ID')),
            'messaging_sender_id' => env('FIREBASE_MESSAGING_SENDER_ID'),
            'app_id' => env('FIREBASE_APP_ID'),
            'vapid_key' => env('FIREBASE_VAPID_KEY'),
        ],
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

];
