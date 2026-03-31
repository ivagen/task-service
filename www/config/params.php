<?php declare(strict_types=1);

return [
    'authServiceUrl' => rtrim($_ENV['AUTH_SERVICE_URL'] ?? 'http://localhost:8000', '/'),
];