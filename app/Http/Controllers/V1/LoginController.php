<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{

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
            'password' => $password
        ])->object();

        return response()->json($auth);

    }

    public function create(Request $request): JsonResponse
    {

        $username = $request->input('username');
        $password = $request->input('password');

        $auth = $this->userService->create([
            'username' => $username,
            'password' => $password
        ])->object();



        return response()->json($auth);

    }

}
