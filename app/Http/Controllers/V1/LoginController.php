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
     * @param Request $request
     * @return JsonResponse
     */
    public function auth(Request $request): JsonResponse
    {
        $username = $request->input('username');
        $password = $request->input('password');

        $auth = $this->userService->auth([
            'username' => $username,
            'password' => $password,
            'token' => $this->token
        ])->object();

        return response()->json($auth);
    }

    /**
     *
     * Some users does not have eak/kdf etc - due to some of them might be registered on StellarSecurity.com,
     * or other places, so we use this method to make sure they have.
     * @param Request $request
     * @return JsonResponse
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

        $user = $this->userService->token($token)->object();

        if (!isset($user->token->id)) {
            return response()->json(null, 400);
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

        $user = $this->userService->patch($patchData)->object();

        return response()->json(['response_code' => 200], 200);
    }


    public function create(Request $request): JsonResponse
    {
        $data = $request->all();
        $data['token'] = $this->token;
        $auth = $this->userService->create($data)->object();
        return response()->json($auth);
    }

    public function sendresetpasswordlink(Request $request): JsonResponse
    {

        $email = $request->input('email');

        if($email === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No email was provided']);
        }

        $confirmation_code = Str::password(6, false, true, false, false);
        $resetpassword = $this->userService->sendresetpasswordlink($email, $confirmation_code)->object();

        if($resetpassword->response_code !== 200) {
            return response()->json(['response_code' => $resetpassword->response_code, 'response_message' => $resetpassword->response_message]);
        }

        return response()->json(['response_code' => 200, 'response_message' => 'OK. Reset password link sent to your email.']);
    }

    public function resetpasswordupdate(Request $request)
    {
        $email = $request->input('email');
        $confirmation_code = $request->input('confirmation_code');
        $new_password = $request->input('new_password');

        if($email === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No email was provided']);
        }

        if($new_password === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'New password not was provided']);
        }

        if($confirmation_code === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No confirmation code was provided']);
        }

        $verifyandupdate = $this->userService->verifyresetpasswordconfirmationcode($email, $confirmation_code, $new_password)->object();

        return response()->json($verifyandupdate);

    }


}
