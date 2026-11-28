<?php

if (getenv('STELLAR_RUNTIME_TEST') !== '1' || !getenv('STELLAR_TEST_AUTOLOAD') || !getenv('STELLAR_TEST_USER_SDK')) exit(1);
require getenv('STELLAR_TEST_AUTOLOAD');
$sdk = realpath(getenv('STELLAR_TEST_USER_SDK'));
require $sdk.'/src/UserService.php';
require dirname(__DIR__).'/app/Http/Controllers/V2/IdentityController.php';
require dirname(__DIR__).'/app/Services/NotesUserService.php';

$app = new Illuminate\Foundation\Application(dirname(__DIR__));
$app->instance('config', new Illuminate\Config\Repository([
    'identity' => ['enabled' => true],
    'stellar-user' => ['base_url' => 'https://identity.example.invalid/api/',
        'username_env_key' => 'TEST_USER', 'password_env_key' => 'TEST_PASSWORD'],
]));
$app->singleton(Illuminate\Contracts\Routing\ResponseFactory::class, fn () => new Illuminate\Routing\ResponseFactory(
    Mockery::mock(Illuminate\Contracts\View\Factory::class), Mockery::mock(Illuminate\Routing\Redirector::class)));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
putenv('TEST_USER=synthetic-service');
putenv('TEST_PASSWORD=synthetic-password');
$http = new class extends Illuminate\Http\Client\Factory {
    protected function newPendingRequest() {
        return parent::newPendingRequest()->setHandler(fn () => throw new LogicException('Real HTTP blocked.'));
    }
};
$http->preventStrayRequests();
$upstream = ['payload' => 'signed-payload', 'signature' => 'signature'];
$status = 200;
$seen = [];
$http->fake(function ($request, $options) use ($http, &$upstream, &$status, &$seen) {
    $seen[] = [$request, $options];
    return $http->response($upstream, $status);
});
Illuminate\Support\Facades\Http::swap($http);
$controller = new App\Http\Controllers\V2\IdentityController(new App\Services\NotesUserService());
$checks = 0;
function same($actual, $expected, $label) {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException($label);
    $checks++;
}
function methodRequest(array $body) {
    $request = Illuminate\Http\Request::create('/api/v2/auth/method', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $route = new Illuminate\Routing\Route('POST', 'api/v2/auth/{operation}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    return $request;
}
$body = ['email' => 'legacy@example.invalid', 'nonce' => rtrim(strtr(base64_encode(str_repeat('n', 32)), '+/', '-_'), '=')];
$response = $controller->handle(methodRequest($body));
same($response->getStatusCode(), 200, 'method success');
same($response->getData(true), $upstream, 'signature bytes preserved');
same(str_contains($response->headers->get('Cache-Control'), 'no-store'), true, 'no cached decisions');
same($seen[0][0]->data(), $body, 'exact signed method payload');
same($seen[0][1]['allow_redirects'], false, 'no redirects');
$before = count($seen);
same($controller->handle(methodRequest($body + ['password' => 'secret']))->getStatusCode(), 401, 'plaintext password rejected');
same(count($seen), $before, 'no forbidden upstream request');
foreach ([401, 409, 429, 503, 500, 302] as $failure) {
    $status = $failure;
    $upstream = ['error' => 'secret-internal-details'];
    $response = $controller->handle(methodRequest($body));
    same($response->getStatusCode(), in_array($failure, [401,409,429,503], true) ? $failure : 503, 'controlled failure');
    same(str_contains($response->getContent(), 'secret-internal'), false, 'internal details hidden');
}
$app['config']->set('identity.enabled', false);
$before = count($seen);
same($controller->handle(methodRequest($body))->getStatusCode(), 503, 'disabled gate');
same(count($seen), $before, 'disabled gateway makes no requests');
$app['config']->set('identity.enabled', true);
$status = 200;
$router = new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher($app), $app);
Illuminate\Support\Facades\Route::swap($router);
$router->prefix('api')->group(dirname(__DIR__).'/routes/api.php');
foreach (['start', 'prove', 'finish'] as $step) {
    $route = $router->getRoutes()->match(Illuminate\Http\Request::create('/api/v2/auth/account/enroll/'.$step, 'POST'));
    same($route->parameter('flow'), 'enroll', 'real gateway route accepts enrollment '.$step);
    same($route->parameter('step'), $step, 'real gateway route binds enrollment step');
}
$upstream = ['challengeId' => str_repeat('c', 64), 'loginResponse' => 'public-login-message'];
$enrollment = ['challengeId' => str_repeat('c', 64), 'registrationRecord' => 'public-record',
    'startLoginRequest' => 'public-message', 'keyEnvelope' => base64_encode(str_repeat('e', 60)), 'confirmationCode' => '12345678'];
function enrollmentRequest(array $body): Illuminate\Http\Request {
    $request = Illuminate\Http\Request::create('/api/v2/auth/account/enroll/prove', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $route = new Illuminate\Routing\Route('POST', 'api/v2/auth/account/{flow}/{step}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    return $request;
}
same($controller->handle(enrollmentRequest($enrollment))->getStatusCode(), 200, 'confirmed enrollment forwards through the SDK');
same(end($seen)[0]->data(), $enrollment, 'confirmation is forwarded without modifying crypto proofs');
same(str_contains(end($seen)[0]->url(), '12345678'), false, 'confirmation code is absent from the URL');
$before = count($seen);
same($controller->handle(enrollmentRequest($enrollment + ['password' => 'must-not-leave-device']))->getStatusCode(), 401, 'enrollment rejects plaintext password fields');
same(count($seen), $before, 'forbidden enrollment password never reaches User API');
$missingCode = $enrollment;
unset($missingCode['confirmationCode']);
same($controller->handle(enrollmentRequest($missingCode))->getStatusCode(), 401, 'enrollment cannot bypass confirmation through the gateway');
same(count($seen), $before, 'unconfirmed enrollment is rejected before HTTP');
$service = new App\Services\NotesUserService();
$token = '10|'.str_repeat('a', 40);
$claims = ['accountId' => '72', 'tokenId' => '10', 'authVersion' => 2, 'abilities' => ['identity:session'], 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time()+300)];
$upstream = $claims;
same($service->token($token)->json('token.tokenable_id'), '72', 'authoritative owner projection');
same(end($seen)[0]->data(), ['token' => $token], 'token in POST body');
same(str_contains(end($seen)[0]->url(), $token), false, 'token absent from URL');
foreach ([['abilities' => ['*']], ['accountId' => '0'], ['authVersion' => 3], ['expiresAt' => '2000-01-01T00:00:00Z'], ['tokenId' => []]] as $mutation) {
    $upstream = array_replace($claims, $mutation);
    same($service->token($token)->status(), 401, 'invalid identity claims denied');
}
$upstream = ['accountId'=>'72','tokenId'=>'10','authVersion'=>1,'abilities'=>['*'],'expiresAt'=>null];
same($service->token($token)->status(), 200, 'legacy tokens work under new gate');
$upstream['abilities'] = ['identity:session'];
same($service->token($token)->status(), 401, 'legacy scoped token cannot gain notes access');
foreach (['finish-renewable'=>['challengeId'=>'synthetic','finishLoginRequest'=>'synthetic-proof'],
    'refresh'=>['refreshToken'=>str_repeat('a',32).'.'.str_repeat('b',43),'requestId'=>str_repeat('c',32)],
    'logout-refresh'=>['refreshToken'=>str_repeat('a',32).'.'.str_repeat('b',43)]] as $operation=>$payload) {
    $status=200;$upstream=['ok'=>true];
    $request=Illuminate\Http\Request::create('/api/v2/auth/'.$operation,'POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($payload));
    $route=new Illuminate\Routing\Route('POST','api/v2/auth/{operation}',fn()=>null);$route->bind($request);$request->setRouteResolver(fn()=>$route);
    $before=count($seen);same($controller->handle($request)->getStatusCode(),200,'renewal operation forwarded');
    same(count($seen),$before+1,'renewal request sent once');same(end($seen)[0]->data(),$payload,'credential stays in exact JSON body');
    same(end($seen)[1]['allow_redirects'],false,'renewal credentials never follow redirects');
    same(str_contains(end($seen)[0]->url(),$payload['refreshToken']??'synthetic-proof'),false,'credentials absent from URL');
}
echo "PASS: $checks isolated mobile gateway checks; fake HTTP only.\n";
