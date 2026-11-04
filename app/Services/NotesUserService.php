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
        if (config('identity.enabled') === true) {
            return $this->identityToken($token);
        }

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

    private function identityToken(#[\SensitiveParameter] string $token): Response
    {
        $response = parent::resolveToken($token);
        if (! $response->successful()) {
            return $response;
        }

        $claims = $response->json();
        $valid = is_array($claims);
        if (! is_array($claims)) $claims = [];
        foreach (['accountId', 'tokenId'] as $key) {
            $valid = $valid && is_string($claims[$key] ?? null) && preg_match('/^[1-9][0-9]{0,19}$/D', $claims[$key]);
        }
        $abilities = $claims['abilities'] ?? null;
        if (($claims['authVersion'] ?? null) === 2) {
            $expiry = is_string($claims['expiresAt'] ?? null)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $claims['expiresAt'], new \DateTimeZone('UTC')) : false;
            $valid = $valid && $abilities === ['identity:session'] && $expiry
                && \DateTimeImmutable::getLastErrors() === false && $expiry->getTimestamp() > time();
        } else {
            $valid = $valid && ($claims['authVersion'] ?? null) === 1 && is_array($abilities) && in_array('*', $abilities, true);
        }
        if (! $valid) {
            return new Response(new \GuzzleHttp\Psr7\Response(401, ['Content-Type' => 'application/json'], '{"response_code":401}'));
        }

        return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
            'response_code' => 200,
            'token' => ['id' => $claims['tokenId'], 'tokenable_id' => $claims['accountId'], 'abilities' => $abilities],
        ], JSON_THROW_ON_ERROR)));
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
