<?php
declare(strict_types=1);

return [
    'host' => getenv('ROJEX_MAIL_HOST') ?: '',
    'port' => (int)(getenv('ROJEX_MAIL_PORT') ?: 587),
    'username' => getenv('ROJEX_MAIL_USERNAME') ?: '',
    'password' => getenv('ROJEX_MAIL_PASSWORD') ?: '',
    'encryption' => strtolower(getenv('ROJEX_MAIL_ENCRYPTION') ?: 'tls'),
    'from_address' => getenv('ROJEX_MAIL_FROM_ADDRESS') ?: '',
    'from_name' => getenv('ROJEX_MAIL_FROM_NAME') ?: 'ROJEX.AI',
    'base_url' => rtrim(getenv('ROJEX_APP_URL') ?: '', '/'),
    'timeout' => 20,
    'max_attempts' => 5,
];
