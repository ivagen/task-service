<?php
return [
    'authServiceUrl' => rtrim($_ENV['AUTH_SERVICE_URL'] ?? 'http://localhost:8000', '/'),
];