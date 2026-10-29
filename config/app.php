<?php
return [
    'name' => getenv('APP_NAME') ?: 'FeatureLab',
    'url' => rtrim(getenv('APP_URL') ?: '', '/'),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Ho_Chi_Minh',
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
    'admin_email' => getenv('APP_ADMIN_EMAIL') ?: 'admin@example.test',
    'admin_password' => getenv('APP_ADMIN_PASSWORD') ?: 'ChangeThisPassword123!',
    'demo_keys' => [
        'staging' => getenv('DEMO_STAGING_API_KEY') ?: 'demo-staging-key-change-me',
        'production_sim' => getenv('DEMO_PRODUCTION_API_KEY') ?: 'demo-production-key-change-me',
    ],
];
