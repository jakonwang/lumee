<?php

namespace App\Apps;

use RuntimeException;

/** Token endpoint error in RFC 6749 shape: {"error": "invalid_grant", "error_description": "…"} */
class OAuthError extends RuntimeException
{
    public function __construct(public string $error, string $description, public int $status = 400)
    {
        parent::__construct($description);
    }
}
