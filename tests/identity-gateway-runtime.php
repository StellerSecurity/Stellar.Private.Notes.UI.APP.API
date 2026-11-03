<?php

if (getenv('STELLAR_RUNTIME_TEST') !== '1' || ! getenv('STELLAR_TEST_USER_API') || ! getenv('STELLAR_TEST_USER_SDK')) {
    exit(1);
}

ob_start();
try {
    $apiRoot = realpath(getenv('STELLAR_TEST_USER_API'));
    $sdkRoot = realpath(getenv('STELLAR_TEST_USER_SDK'));
    if (! $apiRoot || ! $sdkRoot) {
        throw new RuntimeException('Reviewed local User API and SDK paths are required.');
    }
    require $apiRoot.'/tests/full-application-contract.php';
    spl_autoload_register(function (string $class) use ($sdkRoot): void {
        $prefix = 'StellarSecurity\\UserApiLaravel\\';
        if (str_starts_with($class, $prefix)) {
            require $sdkRoot.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    });
    require dirname(__DIR__).'/app/Http/Controllers/V1/LoginController.php';
    applicationSame((new ReflectionClass(StellarSecurity\UserApiLaravel\UserService::class))->getFileName(), $sdkRoot.'/src/UserService.php', 'gateway uses the reviewed first-party SDK');
    putenv('STELLAR_GATEWAY_TEST_USERNAME=synthetic-api-service');
    putenv('STELLAR_GATEWAY_TEST_PASSWORD=synthetic-api-password');
    $app['config']->set('stellar-user', [
        'base_url' => 'https://user-api.example.invalid/api/',
        'username_env_key' => 'STELLAR_GATEWAY_TEST_USERNAME',
        'password_env_key' => 'STELLAR_GATEWAY_TEST_PASSWORD',
    ]);
    $http = new class extends Illuminate\Http\Client\Factory {
        protected function newPendingRequest()
        {
            return parent::newPendingRequest()->setHandler(fn () => throw new LogicException('Real HTTP is blocked.'));
        }
    };
    $http->preventStrayRequests();
    $forwarded = [];
    $http->fake(function ($request, $options) use ($http, &$forwarded, $app) {
        if (! str_starts_with($request->url(), 'https://user-api.example.invalid/api/')) {
            throw new LogicException('Unexpected upstream host.');
        }
        $forwarded[] = ['data' => $request->data(), 'options' => $options];
        $app['auth']->forgetGuards();
        [$status, $body] = applicationRequest($request->method(), parse_url($request->url(), PHP_URL_PATH), $request->data());

        return $http->response($body, $status);
    });
    Illuminate\Support\Facades\Http::swap($http);
    $gateway = new App\Http\Controllers\V1\LoginController(new StellarSecurity\UserApiLaravel\UserService());
    $owner = App\Models\User::create(['name' => 'Gateway owner', 'email' => 'gateway@example.invalid', 'password' => Illuminate\Support\Facades\Hash::make('gateway-password')]);
    $other = App\Models\User::create(['name' => 'Other owner', 'email' => 'other-gateway@example.invalid', 'password' => Illuminate\Support\Facades\Hash::make('other-password')]);
    $token = $owner->createToken('Existing.Notes.Client')->plainTextToken;
    $otherToken = $other->createToken('Existing.Notes.Client')->plainTextToken;
    $input = [
        'id' => $other->getKey(),
        'eak' => base64_encode(str_repeat('e', 60)),
        'kdf_salt' => base64_encode(str_repeat('s', 16)),
        'kdf_params' => ['algo' => 'PBKDF2', 'hash' => 'SHA-256', 'iters' => 100000],
        'crypto_version' => 'v1',
    ];
    $request = Illuminate\Http\Request::create('/api/v1/logincontroller/updateEak', 'PATCH', $input, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    $response = $gateway->updateEak($request);
    applicationSame($response->getStatusCode(), 200, 'unchanged client key update works through gateway and SDK');
    applicationSame($owner->fresh()->eak_b64, $input['eak'], 'gateway updates only the token owner');
    applicationSame($other->fresh()->eak_b64, null, 'caller-selected user ID cannot change another account');
    applicationSame(end($forwarded)['data']['access_token'], $token, 'gateway forwards the existing bearer proof');
    [$tokenId, $legacySecret] = explode('|', $token, 2);
    $request = Illuminate\Http\Request::create('/api/v1/logincontroller/updateEak', 'PATCH', $input, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$legacySecret]);
    applicationSame($gateway->updateEak($request)->getStatusCode(), 200, 'historical secret-only client token also updates its own key');

    $request = Illuminate\Http\Request::create('/api/v1/logincontroller/deleteuser', 'POST', ['current_password' => 'gateway-password'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer invalid-token']);
    $before = count($forwarded);
    applicationSame($gateway->deleteuser($request)->getStatusCode(), 401, 'malformed client deletion token is a controlled authentication failure');
    applicationSame(count($forwarded), $before, 'malformed token never reaches User API');

    $request = Illuminate\Http\Request::create('/api/v1/logincontroller/deleteuser', 'POST', ['current_password' => 'wrong-password'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    $before = count($forwarded);
    $response = $gateway->deleteuser($request);
    applicationSame($response->getStatusCode(), 422, 'deletion failure status crosses both services unchanged');
    applicationSame(count($forwarded), $before + 1, 'failed deletion is sent once');
    applicationSame($owner->fresh() !== null, true, 'wrong password cannot delete the owner');
    $request = Illuminate\Http\Request::create('/api/v1/logincontroller/deleteuser', 'POST', ['current_password' => 'gateway-password', 'user_id' => $other->getKey()], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    $response = $gateway->deleteuser($request);
    applicationSame($response->getStatusCode(), 200, 'secured SDK deletion succeeds through the existing client route');
    applicationSame($owner->fresh(), null, 'only the bearer owner is deleted');
    applicationSame($other->fresh() !== null, true, 'unrelated account remains intact');
    applicationSame(array_keys(end($forwarded)['data']), ['access_token', 'current_password'], 'gateway never forwards a caller-selected deletion ID');

    Illuminate\Support\Facades\Mail::fake();
    $response = $gateway->sendresetpasswordlink(Illuminate\Http\Request::create('/reset', 'POST', ['email' => $other->email]));
    applicationSame($response->getData(true)['response_code'], 200, 'existing client reset request works through the gateway');
    $mail = Illuminate\Support\Facades\Mail::sent(App\Mail\ResetPasswordLink::class)->sole();
    $password = '  exact recovered password  ';
    $response = $gateway->resetpasswordupdate(Illuminate\Http\Request::create('/reset', 'POST', [
        'email' => $other->email, 'confirmation_code' => $mail->data['confirmation_code'], 'new_password' => $password,
    ]));
    applicationSame($response->getData(true)['response_code'], 200, 'emailed server code resets the legacy account through both services');
    applicationSame(Illuminate\Support\Facades\Hash::check($password, $other->fresh()->password), true, 'gateway and SDK preserve exact recovered password bytes');
    applicationSame(App\Models\PersonalAccessToken::findToken($otherToken), null, 'cross-service recovery revokes the old client session');
    applicationSame(str_contains(file_get_contents(dirname(__DIR__).'/bootstrap/app.php'), "trimStrings(except: ['new_password', \$notesRequest])"), true, 'UI middleware excludes the actual password field');
    echo 'PASS: '.$checks.' combined User API/SDK/Notes gateway checks; synthetic data, fake HTTP/mail, no network.'.PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Identity gateway test failed: '.$error->getMessage().PHP_EOL);
    exit(1);
} finally {
    ob_end_flush();
}
