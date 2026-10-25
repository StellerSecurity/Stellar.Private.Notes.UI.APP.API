<?php
// Existing installed dependencies only; no application bootstrap, .env or real HTTP.
if (PHP_SAPI !== 'cli' || getenv('STELLAR_RUNTIME_TEST') !== '1') exit(2);
foreach (['STELLAR_SYNTHETIC_USERNAME'=>'synthetic', 'STELLAR_SYNTHETIC_PASSWORD'=>'synthetic'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
$autoload = getenv('STELLAR_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) exit(2);
require $autoload;
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php';
        if (is_file($file)) require $file;
    }
}, true, true);
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Services\NotesUserService;
use App\Http\Controllers\V1\LoginController;
$app = new Container(); Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('config', new Repository(['stellar-user'=>['base_url'=>'https://synthetic-user.invalid/api/',
    'username_env'=>'STELLAR_SYNTHETIC_USERNAME', 'password_env'=>'STELLAR_SYNTHETIC_PASSWORD']]));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class,
    (new ReflectionClass(Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
$app->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory());
$validator = new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'), $app);
Request::macro('validate', function (array $rules) use ($validator) { return $validator->make($this->all(),$rules)->validate(); });
Http::preventStrayRequests();
$calls=[]; $status=409; $abilities=['*']; $legacyMetadata=false;
Http::fake(function ($request) use (&$calls, &$status, &$abilities, &$legacyMetadata) {
    $calls[] = [$request->url(), $request->data()];
    if (str_contains($request->url(), '/personaltokencontroller/')) return Http::response(['token'=>['id'=>1,'tokenable_id'=>11]+($legacyMetadata ? [] : ['abilities'=>$abilities])],200);
    if (str_ends_with($request->url(), '/patch')) return Http::response(['response_code'=>$status, 'error_code'=>'NOTES_KEY_UPDATE_REQUIRES_V2'],$status);
    if (str_ends_with($request->url(), '/deleteuser')) return Http::response(['response_code'=>$status],$status);
    throw new RuntimeException('Unexpected outbound request');
});
$service = new NotesUserService(); $app->instance(NotesUserService::class,$service);
$controller = new LoginController($service);
$request = function ($data, $token='77|synthetic-session') {
    $headers=[];
    if ($token !== null) $headers['HTTP_AUTHORIZATION']='Bearer '.$token;
    return Request::create('/synthetic','PATCH',$data,[],[],$headers);
};
$checks=0;
function check(bool $value, string $label): void { global $checks; if (!$value) throw new RuntimeException($label); ++$checks; echo 'PASS '.$label."\n"; }
$bundle=['crypto_version'=>'v1','kdf_params'=>['algo'=>'PBKDF2','hash'=>'SHA-256','iters'=>210000],
    'kdf_salt'=>base64_encode(str_repeat('s',16)), 'eak'=>base64_encode(str_repeat('e',60))];
check($controller->updateEak($request($bundle,null))->getStatusCode()===401, 'missing token cannot update keys');
check(count($calls)===0,'unauthenticated key request makes no HTTP call');
$response=$controller->updateEak($request($bundle+['id'=>999]));
check($response->getStatusCode()===409 && $response->getData(true)['error_code']==='NOTES_KEY_UPDATE_REQUIRES_V2', 'upstream key conflict reaches old client unchanged');
check(count($calls)===2, 'key conflict is not retried');
check($calls[1][1]['id']===11, 'key target comes from token owner, not request ID');
$status=200; $calls=[];
check($controller->updateEak($request($bundle))->getStatusCode()===200, 'successful legacy update keeps 200 response');
$legacyMetadata=true; $calls=[];
check($controller->updateEak($request($bundle))->getStatusCode()===200, 'older introspection without abilities preserves existing Notes session');
check(count($calls)===2 && $calls[1][1]['id']===11, 'legacy session retains authenticated owner without requesting a new token');
$legacyMetadata=false;
foreach ([200,422,429,503] as $status) {
    $calls=[];
    $response=$controller->deleteuser($request(['current_password'=>'synthetic-password','user_id'=>999]));
    check($response->getStatusCode()===$status, 'deletion status '.$status.' preserved');
    check(count($calls)===2, 'deletion is sent once for status '.$status);
    check($calls[1][1]===['access_token'=>'77|synthetic-session','current_password'=>'synthetic-password'], 'deletion sends session proof and never user_id');
}
$calls=[];
check($controller->deleteuser($request(['current_password'=>'synthetic-password'],null))->getStatusCode()===401, 'missing token cannot delete account');
check(count($calls)===0,'unauthenticated deletion makes no HTTP call');
foreach ([['vpn'], [], null, '*'] as $abilities) {
    $calls=[];
    check($controller->updateEak($request($bundle))->getStatusCode()===401, 'scoped or malformed session cannot update Notes keys');
    check(count($calls)===1, 'unauthorized scope never reaches key mutation');
}
echo 'Key API runtime: '.$checks.' checks; framework '.Illuminate\Foundation\Application::VERSION."; fake HTTP only.\n";
