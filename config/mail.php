<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */
    'mailers' => [

        'smtp' => [
            'transport'    => 'smtp',
            'url'          => env('MAIL_URL'),
            'host'         => env('MAIL_HOST', '127.0.0.1'),
            'port'         => env('MAIL_PORT', 1025),
            'encryption'   => env('MAIL_ENCRYPTION', 'tls'),
            'username'     => env('MAIL_USERNAME'),
            'password'     => env('MAIL_PASSWORD'),
            'timeout'      => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'resend' => [
            'transport' => 'resend',
        ],

       'mailtrap' => [
            'transport' => 'mailtrap',
        ],
        'mailersend' => [
            'transport' => 'mailersend',
        ],

        'mailgun' => [
            'transport' => 'mailgun',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path'      => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel'   => storage_path('logs/laravel.log'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers'   => ['resend', 'mailersend', 'mailgun', 'postmark'],
        ],

       'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers'   => ['ses', 'resend', 'postmark', 'mailgun'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@reu.ng'),
        'name' => env('MAIL_FROM_NAME', 'REU.ng'),
    ],

    // Kept separate from 'from' on purpose: mail sends FROM no-reply@reu.ng
    // (keeps automated/bulk sending isolated from the support inbox for
    // deliverability/reputation reasons), but customer-facing emails often
    // say "reply to this email" — that reply needs to land somewhere a
    // person actually reads. Applied per-Mailable via ->replyTo(...), see
    // App\Mail\MarketingMail and friends.
    'reply_to' => [
        'address' => env('MAIL_REPLY_TO_ADDRESS', 'support@reu.ng'),
        'name' => env('MAIL_REPLY_TO_NAME', 'REU.ng Support'),
    ],
    
    // 'to' => [
    //     'address' => env('SUPPORT_ADDRESS', 'support@reu.ng'),
    //     'name' => env('SUPPORT_NAME', 'REU.ng Support'),
    // ],

];
