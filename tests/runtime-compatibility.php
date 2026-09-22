<?php
/** Real Laravel HTTP smoke test with synthetic identities and no external HTTP. */
if (PHP_SAPI !== 'cli' || getenv('STELLAR_RUNTIME_TEST') !== '1') {
    fwrite(STDERR, "Run only in an explicitly isolated runtime-test process.\n"); exit(2);
}
$root = dirname(__DIR__);
$scratch = sys_get_temp_dir().'/stellar-ui-runtime-'.bin2hex(random_bytes(12));
if (!mkdir($scratch, 0700)) { fwrite(STDERR, "Cannot create isolated test directory.\n"); exit(1); }
foreach (['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'] as $dir) {
    if (!mkdir($scratch.'/'.$dir, 0700, true)) { fwrite(STDERR, "Cannot create isolated test storage.\n"); exit(1); }
}
$environment = [
    'APP_ENV'=>'testing', 'APP_DEBUG'=>'false', 'APP_KEY'=>'base64:'.base64_encode(str_repeat('k',32)),
    'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>':memory:', 'CACHE_STORE'=>'array',
    'SESSION_DRIVER'=>'array', 'LOG_CHANNEL'=>'null', 'QUEUE_CONNECTION'=>'sync',
    'BASE_URL_NOTES_API'=>'http://stellar-notes.invalid/api/',
    'APPSETTING_API_USERNAME_STELLAR_NOTES_API'=>'runtime-user',
    'APPSETTING_API_PASSWORD_STELLAR_NOTES_API'=>'runtime-password',
    'APPSETTING_API_USERNAME_STELLAR_USER_API'=>'runtime-user',
    'APPSETTING_API_PASSWORD_STELLAR_USER_API'=>'runtime-password',
    'NOTES_REALTIME_ENABLED'=>'false',
    'APP_CONFIG_CACHE'=>$scratch.'/config.php', 'APP_SERVICES_CACHE'=>$scratch.'/services.php',
    'APP_PACKAGES_CACHE'=>$scratch.'/packages.php', 'APP_ROUTES_CACHE'=>$scratch.'/routes.php',
    'APP_EVENTS_CACHE'=>$scratch.'/events.php',
];
foreach ($environment as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value; }
$checks=0;
class RuntimeCheckFailure extends RuntimeException {}
function checkRuntime(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeCheckFailure($label);
    ++$checks; echo 'PASS runtime: '.$label."\n";
}
try {
    require $root.'/vendor/autoload.php';
    $app=require $root.'/bootstrap/app.php';
    $app->useEnvironmentPath($scratch);
    $app->useStoragePath($scratch.'/storage');
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    config(['stellar-user.base_url'=>'http://stellar-user.invalid/api/', 'realtime.enabled'=>false]);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $logs=new class extends Psr\Log\AbstractLogger {
        public array $entries=[];
        public function log($level, string|Stringable $message, array $context=[]): void { $this->entries[]=[$level,(string)$message,$context]; }
    };
    Illuminate\Support\Facades\Log::swap($logs);
    checkRuntime($app->make(StellarSecurity\UserApiLaravel\UserService::class) instanceof App\Services\NotesUserService, 'real service provider binds safe identity client');
    $userStatus=200; $notesStatus=200; $captured=[]; $urls=[]; $userId='11'; $connectionFailure=false;
    Illuminate\Support\Facades\Http::fake(function ($request) use (&$userStatus,&$notesStatus,&$captured,&$urls,&$userId,&$connectionFailure) {
        $urls[]=$request->url();
        if (str_starts_with($request->url(),'http://stellar-user.invalid/api/v1/personaltokencontroller/')) {
            if ($connectionFailure) throw new Illuminate\Http\Client\ConnectionException('PRIVATE synthetic token URL');
            return Illuminate\Support\Facades\Http::response($userStatus===200 ? ['token'=>['id'=>1,'tokenable_id'=>$userId]] : ['message'=>'PRIVATE upstream body'], $userStatus);
        }
        if (str_starts_with($request->url(),'http://stellar-notes.invalid/api/v1/notecontroller/')) {
            $captured[]=$request->data();
            $action=basename($request->url());
            $body=$action==='upload' ? ['ok'=>true] : ['notes'=>[['id'=>'test-note','text'=>"  cipher\nline two\nline three  ",'favorite'=>false]], 'folders'=>[]];
            return Illuminate\Support\Facades\Http::response($notesStatus===200 ? $body : ['message'=>'PRIVATE notes body'], $notesStatus);
        }
        throw new RuntimeCheckFailure('unexpected outbound HTTP denied');
    });
    $call=function(string $action,array $data=[],?string $token='77|synthetic-token') use ($kernel): array {
        $server=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'127.0.0.1'];
        if($token!==null) $server['HTTP_AUTHORIZATION']='Bearer '.$token;
        $request=Illuminate\Http\Request::create('/api/v1/notescontroller/'.$action,'POST',[],[],[],$server,json_encode($data));
        $response=$kernel->handle($request);
        checkRuntime(!str_contains($response->getContent(),'PRIVATE'),'no upstream private data in '.$action.' response');
        return [$response->getStatusCode(),json_decode($response->getContent(),true)];
    };
    foreach(['upload','download','find','sync-plan'] as $action) checkRuntime($call($action,[],null)[0]===401,'unauthenticated '.$action.' denied');
    checkRuntime($urls===[], 'missing token never calls upstream services');
    $text="  cipher\nline two\nline three  ";
    $note=['id'=>'test-note','text'=>$text,'title'=>'  encrypted title  ','last_modified'=>'100','favorite'=>'false','folder'=>'','folder_id'=>''];
    checkRuntime($call('upload',['user_id'=>'999','notes'=>[$note]])===[200,['ok'=>true]],'legacy upload response unchanged');
    $payload=end($captured);
    checkRuntime($payload['user_id']==='11','forged user id replaced by authenticated identity');
    checkRuntime($payload['notes'][0]['text']===$text && $payload['notes'][0]['title']===$note['title'],'actual middleware preserves encrypted whitespace and line breaks');
    checkRuntime($payload['notes'][0]['folder']==='' && $payload['notes'][0]['folder_id']===null,'legacy empty folder normalized correctly');
    checkRuntime($payload['notes'][0]['favorite']===false && $payload['notes'][0]['last_modified']===100,'legacy scalar normalization unchanged');
    checkRuntime(in_array('http://stellar-user.invalid/api/v1/personaltokencontroller/77%7Csynthetic-token',$urls,true),'identity token encoded in actual upstream URL');
    checkRuntime($call('download')[1]['notes'][0]['text']===$text,'download preserves line breaks and whitespace');
    $userId='12';
    checkRuntime($call('download',['user_id'=>'11'])[0]===200 && end($captured)['user_id']==='12','second account uses its own authenticated identity');
    $userId='11';
    foreach ([401=>401,403=>401,404=>401,408=>502,429=>502,500=>502,503=>502] as $upstream=>$expected) {
        $userStatus=$upstream; $count=count($captured);
        checkRuntime($call('download')[0]===$expected,'real identity failure '.$upstream.' returns '.$expected);
        checkRuntime(count($captured)===$count,'identity failure cannot access Notes API');
    }
    $userStatus=200; $connectionFailure=true;
    checkRuntime($call('download')[0]===502,'network outage preserves login failure category');
    $connectionFailure=false; $notesStatus=503;
    checkRuntime($call('upload',['notes'=>[$note]])[0]===502,'failed save never returns success');
    $notesStatus=200;
    checkRuntime($call('realtime')===[200,['enabled'=>false]],'disabled realtime retains polling fallback contract');
    checkRuntime($call('download')[0]===200,'polling recovers after transient failure');
    $logged=json_encode($logs->entries);
    checkRuntime(count($logs->entries)>=8,'upstream failures retain sanitized diagnostics');
    checkRuntime(!str_contains($logged,'PRIVATE') && !str_contains($logged,'synthetic-token') && !str_contains($logged,'runtime-password'),'failure logs exclude upstream bodies and credentials');
    echo 'Runtime compatibility: '.$checks.' checks passed; PHP '.PHP_VERSION.'; Laravel '.$app->version()."\n";
} catch (Throwable $e) {
    fwrite(STDERR,'Runtime compatibility failed: '.($e instanceof RuntimeCheckFailure ? $e->getMessage() : get_class($e).' at '.basename($e->getFile()).':'.$e->getLine())."\n");
    $failed=true;
} finally {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry) { if($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($scratch);
}
exit(isset($failed)?1:0);
