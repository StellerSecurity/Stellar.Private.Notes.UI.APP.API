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
        $response = parent::token(rawurlencode($token));
        if ($response->successful() && isset($response->object()->token)) {
            $abilities = $response->json('token.abilities');
            // Older User API responses omit abilities. Preserve that successful
            // introspection contract without forcing existing Notes users to log in.
            // Explicit scoped/malformed abilities must not grant Notes authority.
            if (property_exists($response->object()->token, 'abilities')
                && (!is_array($abilities) || !in_array('*', $abilities, true))) {
                return new Response(new \GuzzleHttp\Psr7\Response(401,
                    ['Content-Type' => 'application/json'], '{"response_code":401}'));
            }
        }
        return $response;
    }

    public function patch(array $data): Response
    {
        // Preserve key-conflict status so old clients cannot mistake it for success.
        return $this->client()->retry(1, 0, null, false)->patch(
            $this->baseUrl . 'v1/usercontroller/patch', $data
        );
    }

    public function deleteAccountForSession(string $token, string $currentPassword): Response
    {
        // Current User API derives the owner from access_token and prohibits user_id.
        // A destructive mutation must not be automatically retried by the SDK.
        return $this->client()->retry(1, 0, null, false)->post(
            $this->baseUrl . 'v1/usercontroller/deleteuser',
            ['access_token' => $token, 'current_password' => $currentPassword]
        );
    }
}
