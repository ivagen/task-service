<?php declare(strict_types=1);

namespace app\components;

/**
 * The auth service could not answer — the token is neither valid nor invalid.
 * Must surface as 503, never as 401.
 */
class AuthServiceUnavailableException extends \RuntimeException
{
}
