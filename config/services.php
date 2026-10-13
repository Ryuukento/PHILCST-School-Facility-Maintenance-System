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

    /*
    |--------------------------------------------------------------------------
    | IPROG (SMS)
    |--------------------------------------------------------------------------
    |
    | Used by App\Services\SmsService to send the Administrator Need Change
    | SMS alert. Credentials come from the environment ONLY — never hardcode
    | a real API token here or anywhere else in source control. SmsService
    | already no-ops safely (logs and returns false) when IPROG_SMS_API_TOKEN
    | is unset, so leaving these blank in a fresh .env is always safe and
    | never breaks report creation/update.
    |
    | 2026-10-13 — replaced Semaphore (never actually provisioned with a real
    | key) with IPROG (https://sms.iprogtech.com), the provider actually
    | purchased/used. See https://sms.iprogtech.com/api/v1/documentation.
    | sms_provider is IPROG's own optional routing param (0/1/2); 0 is its
    | documented default and is fine to leave as-is unless IPROG support says
    | otherwise.
    |
    */

    'iprog' => [
        'api_token' => env('IPROG_SMS_API_TOKEN'),
        'api_url' => env('IPROG_SMS_API_URL', 'https://sms.iprogtech.com/api/v1/sms_messages'),
        'sms_provider' => env('IPROG_SMS_PROVIDER', 0),
    ],

];
