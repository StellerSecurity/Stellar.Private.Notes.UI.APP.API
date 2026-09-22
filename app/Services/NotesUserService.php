<?php
namespace App\Services;

use Illuminate\Http\Client\Response;
use StellarSecurity\UserApiLaravel\UserService;

class NotesUserService extends UserService
{
    protected function client()
    {
        return parent::client()->connectTimeout(5)->timeout(15);
    }

    public function token(string $token): Response
    {
        // The token is one URL path segment, never a URL or query supplied by a client.
        return parent::token(rawurlencode($token));
    }
}
