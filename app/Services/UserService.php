<?php

namespace App\Services;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class UserService
{

    private string $base_url;

    private string $username_key = "APPSETTING_API_USERNAME_STELLAR_USER_API";

    private string $password_key = "APPSETTING_API_PASSWORD_STELLAR_USER_API";

    public function __construct() {
        $this->base_url = env('BASE_URL_USER_API');
    }

    /**
     * @param string $id
     * @return Response
     */
    public function user(string $id): Response
    {
        $response = Http::withBasicAuth(getenv($this->username_key),getenv($this->password_key))
            ->get($this->base_url . "v1/usercontroller/user/$id");
        return $response;
    }

    public function sendresetpasswordlink(string $email): PromiseInterface|Response
    {
        $response = Http::withBasicAuth(getenv($this->username_key), getenv($this->password_key))->retry(3)
            ->post($this->base_url . "v1/usercontroller/sendresetpasswordlink?email=" . $email);
        return $response;
    }

    /**
     * @param array $data
     * @return PromiseInterface|Response
     */
    public function create(array $data): PromiseInterface|Response
    {
        $response = Http::withBasicAuth(getenv($this->username_key), getenv($this->password_key))
            ->post($this->base_url . "v1/usercontroller/createuser", $data);
        return $response;
    }

    /**
     * @param array $data
     * @return PromiseInterface|Response
     */
    public function auth(array $data): PromiseInterface|Response
    {
        $response = Http::withBasicAuth(getenv($this->username_key), getenv($this->password_key))
            ->post($this->base_url . "v1/usercontroller/login", $data);
        return $response;
    }



}
