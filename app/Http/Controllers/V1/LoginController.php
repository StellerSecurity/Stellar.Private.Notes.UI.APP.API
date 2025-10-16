<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LoginController extends Controller
{

    private string $token = "Stellar.Private.Notes.UI.APP.API";

    public function __construct(public UserService $userService)
    {}

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
