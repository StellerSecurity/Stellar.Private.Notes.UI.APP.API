<?php

namespace App\Services;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;


class NotesService
{

    private string $base_url;

    private $username_key = "APPSETTING_API_USERNAME_STELLAR_NOTES_API";

    private $password_key = "APPSETTING_API_PASSWORD_STELLAR_NOTES_API";

    public function __construct() {
        $this->base_url = env('BASE_URL_NOTES_API');
    }


    public function upload(array $data): PromiseInterface|Response|null
    {
        try {
            $response = Http::withBasicAuth(getenv($this->username_key), getenv($this->password_key))->retry(3)->timeout(15)
                ->post($this->base_url . "v1/notecontroller/upload", $data);
        } catch (RequestException $exception) {
            return null;
        }
        return $response;
    }

    public function download(array $data): PromiseInterface|Response|null
    {
        try {
            $response = Http::withBasicAuth(getenv($this->username_key), getenv($this->password_key))->retry(3)->timeout(15)
                ->get($this->base_url . "v1/notecontroller/download", $data);
        } catch (RequestException $exception) {
            return $exception->response();
        }
        return $response;
    }

    public function find(string $id): PromiseInterface|Response|null
    {
        try {
            $response = Http::withBasicAuth(getenv($this->username_key), getenv($this->password_key))->retry(3)->timeout(15)
                ->get($this->base_url . "v1/notecontroller/find?id=$id");
        } catch (RequestException $exception) {
            return null;
        }
        return $response;
    }

    public function delete(array $data): PromiseInterface|Response|null
    {
        try {
            $response = Http::withBasicAuth(getenv($this->username_key), getenv($this->password_key))->retry(3)->timeout(15)
                ->post($this->base_url . "v1/notecontroller/sync-plan", $data);
        } catch (RequestException $exception) {
            return null;
        }
        return $response;
    }



}
