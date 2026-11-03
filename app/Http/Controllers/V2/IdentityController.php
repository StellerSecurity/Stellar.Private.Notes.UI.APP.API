<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StellarSecurity\UserApiLaravel\UserService;

final class IdentityController extends Controller
{
    public function __construct(private UserService $users)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store, private'];
        if (config('identity.enabled') !== true) {
            return response()->json(['error' => 'OPAQUE_UNAVAILABLE'], 503, $headers);
        }

        try {
            if (! $request->isJson() || strlen($request->getContent()) > 65536) {
                throw new \InvalidArgumentException();
            }
            $object = json_decode($request->getContent(), false, 8, JSON_THROW_ON_ERROR);
            if (! $object instanceof \stdClass) {
                throw new \InvalidArgumentException();
            }
            $body = (array) $object;
            $operation = $request->route('operation');
            $flow = $request->route('flow');
            $step = $request->route('step');
            if ($flow !== null) {
                $response = match ($step) {
                    'start' => $this->users->startOpaqueAccount($flow, $body),
                    'prove' => $this->users->proveOpaqueAccount($flow, $body),
                    'finish' => $this->users->finishOpaqueAccount($flow, $body),
                    default => throw new \InvalidArgumentException(),
                };
            } else {
                $expected = match ($operation) {
                    'method' => ['email', 'nonce'],
                    'start' => ['email', 'startLoginRequest'],
                    'finish' => ['challengeId', 'finishLoginRequest'],
                    'session', 'logout' => ['token'],
                    default => throw new \InvalidArgumentException(),
                };
                $actual = array_keys($body);
                sort($actual);
                sort($expected);
                if ($actual !== $expected || array_filter($body, fn ($value) => ! is_string($value)) !== []) {
                    throw new \InvalidArgumentException();
                }
                $response = match ($operation) {
                    'method' => $this->users->getLoginMethod($body['email'], $body['nonce']),
                    'start' => $this->users->startOpaqueLogin($body['email'], $body['startLoginRequest']),
                    'finish' => $this->users->finishOpaqueLogin($body['challengeId'], $body['finishLoginRequest']),
                    'session' => $this->users->inspectOpaqueSession($body['token']),
                    'logout' => $this->users->logoutOpaqueSession($body['token']),
                };
            }
            if (! $response->successful()) {
                $status = in_array($response->status(), [401, 409, 429, 503], true) ? $response->status() : 503;

                return response()->json(['error' => $status === 409 ? 'OPAQUE_CONFLICT' : ($status === 503 ? 'OPAQUE_UNAVAILABLE' : 'OPAQUE_REJECTED')], $status, $headers);
            }
            if (strlen($response->body()) > 65536) {
                throw new \RuntimeException();
            }
            $result = json_decode($response->body(), false, 8, JSON_THROW_ON_ERROR);
            if (! $result instanceof \stdClass) {
                throw new \RuntimeException();
            }

            return response()->json($result, 200, $headers);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'OPAQUE_REJECTED'], 401, $headers);
        } catch (\Throwable) {
            return response()->json(['error' => 'OPAQUE_UNAVAILABLE'], 503, $headers);
        }
    }
}
