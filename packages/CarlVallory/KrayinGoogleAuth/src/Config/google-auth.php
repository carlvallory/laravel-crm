<?php

return [
    'allowed_domains'         => ['muci.org'],
    'default_role_name'       => 'Básico',
    'show_password_login'     => env('GOOGLE_AUTH_SHOW_PASSWORD_LOGIN', false),
    'uninstall_fallback_role' => 'Administrator',

    'credentials' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],
];
