<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use StellarSecurity\UserApiLaravel\UserService;

class LoginController extends Controller
{
    private string $token = "Stellar.Private.Notes.UI.APP.API";

    public function __construct(public UserService $userService)
    {
    }

    /**
     * Authenticate user via UserService
     */
    public function auth(Request $request): JsonResponse
    {
        $username = $request->input('username');
        $password = $request->input('password');

        if ($username === null || $password === null) {
            return response()->json([
                'response_code'    => 400,
                'response_message' => 'Username or password missing',
            ], 400);
        }

        $response = $this->userService->auth([
            'username' => $username,
            'password' => $password,
            'token'    => $this->token,
        ]);

        return response()->json($response->object());
    }

    /**
     * Some users does not have eak/kdf etc - due to some of them might be registered on StellarSecurity.com,
     * or other places, so we use this method to make sure they have.
     */
    public function updateEak(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'crypto_version'        => ['required', 'string', 'in:v1'],
            'kdf_params'            => ['required', 'array'],
            'kdf_params.algo'       => ['required', 'string', 'in:PBKDF2'],
            'kdf_params.hash'       => ['required', 'string', 'in:SHA-256'],
            'kdf_params.iters'      => ['required', 'integer', 'min:1'],
            'kdf_salt'              => ['required', 'string'],
            'eak'                   => ['required', 'string'],
        ]);

        $token = $request->bearerToken();

        if (empty($token)) {
            return response()->json(null, 401);
        }

        $userResponse = $this->userService->token($token);

        if ($userResponse->failed()) {
            return response()->json(null, 401);
        }

        $user = $userResponse->object();

        if (! isset($user->token->id)) {
            return response()->json(null, 401);
        }

        $user_id = $user->token->tokenable_id;

        $patchData = [
            'id'             => $user_id,
            'crypto_version' => $payload['crypto_version'],
            'kdf_params'     => [
                'algo'  => $payload['kdf_params']['algo'],
                'hash'  => $payload['kdf_params']['hash'],
                'iters' => $payload['kdf_params']['iters'],
            ],
            'kdf_salt'       => $payload['kdf_salt'],
            'eak'            => $payload['eak'],
        ];

        $patchResponse = $this->userService->patch($patchData);

        return response()->json($patchResponse->object(), 200);
    }

    public function create(Request $request): JsonResponse
    {
        $data          = $request->all();
        $data['token'] = $this->token;

        $response = $this->userService->create($data);

        return response()->json($response->object());
    }

    public function sendresetpasswordlink(Request $request): JsonResponse
    {
        $email = $request->input('email');

        if ($email === null) {
            return response()->json([
                'response_code'    => 400,
                'response_message' => 'No email was provided',
            ], 400);
        }

        $confirmation_code = Str::password(6, false, true, false, false);

        $response = $this->userService->sendresetpasswordlink($email, $confirmation_code);

        $resetpassword = $response->object();

        if (isset($resetpassword->response_code) && $resetpassword->response_code !== 200) {
            return response()->json([
                'response_code'    => $resetpassword->response_code,
                'response_message' => $resetpassword->response_message ?? 'Reset failed',
            ], 400);
        }

        return response()->json([
            'response_code'    => 200,
            'response_message' => 'OK. Reset password link sent to your email.',
        ]);
    }

    public function resetpasswordupdate(Request $request): JsonResponse
    {
        $email             = $request->input('email');
        $confirmation_code = $request->input('confirmation_code');
        $new_password      = $request->input('new_password');

        if ($email === null) {
            return response()->json([
                'response_code'    => 400,
                'response_message' => 'No email was provided',
            ], 400);
        }

        if ($new_password === null) {
            return response()->json([
                'response_code'    => 400,
                'response_message' => 'New password was not provided',
            ], 400);
        }

        if ($confirmation_code === null) {
            return response()->json([
                'response_code'    => 400,
                'response_message' => 'No confirmation code was provided',
            ], 400);
        }

        $response = $this->userService
            ->verifyresetpasswordconfirmationcode($email, $confirmation_code, $new_password);

        return response()->json($response->object());
    }
}
