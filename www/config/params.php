<?php declare(strict_types=1);

use app\components\Env;

return [
    'authServiceUrl' => rtrim(Env::get('AUTH_SERVICE_URL', 'http://localhost:8000'), '/'),
];
