<?php
namespace Illuminate\Http {
    class Request {public function __construct(private string $path='api/v1/notescontroller/download'){} public function is($pattern){return fnmatch($pattern,$this->path);}}
}
namespace Illuminate\Http\Client {
    class Response {public function __construct(private int $code=200){} public function status(){return $this->code;}}
    class ConnectionException extends \RuntimeException {}
    class RequestException extends \RuntimeException {public function __construct(public Response $response){parent::__construct('PRIVATE upstream body and credentials');}}
}
namespace StellarSecurity\UserApiLaravel {
    class UserService {
        public string $received=''; public array $options=[];
        public function token(string $token): \Illuminate\Http\Client\Response {$this->received=$token;return new \Illuminate\Http\Client\Response();}
        protected function client(){return new class($this) {
            public function __construct(private $owner){} public function connectTimeout($n){$this->owner->options['connect']=$n;return $this;} public function timeout($n){$this->owner->options['total']=$n;return $this;}
        };}
    }
}
namespace {
    function response(){return new class {function json($data,$status=200,$headers=[]){return (object)compact('data','status','headers');}};}
    require __DIR__.'/../app/Support/UpstreamServiceErrors.php';
    require __DIR__.'/../app/Services/NotesUserService.php';
    function check($label,$ok){if(!$ok)throw new \RuntimeException($label);echo 'PASS: '.$label."\n";}
    $middleware=new \App\Support\UpstreamServiceErrors();$request=new \Illuminate\Http\Request();
    foreach([401=>401,403=>401,404=>401,408=>502,429=>502,500=>502,503=>502] as $upstream=>$expected){
        $r=$middleware->render($request,new \Illuminate\Http\Client\RequestException(new \Illuminate\Http\Client\Response($upstream)));
        check('upstream '.$upstream.' becomes '.$expected,$r->status===$expected);
        check('provider body remains private '.$upstream,!str_contains(json_encode($r),'PRIVATE') && $r->headers['Cache-Control']==='no-store');
    }
    $r=$middleware->render($request,new \Illuminate\Http\Client\ConnectionException('private service endpoint'));
    check('connection outage does not claim invalid credentials',$r->status===502);
    $r=$middleware->render(new \Illuminate\Http\Request('api/v1/logincontroller/create'),new \Illuminate\Http\Client\RequestException(new \Illuminate\Http\Client\Response(422)));
    check('account validation failure retains category',$r->status===422);
    $service=new class extends \App\Services\NotesUserService {public function configureClient(){return $this->client();}};
    foreach(['77|normal-token','a/../b?query=value#fragment','already%encoded+token'] as $token){$service->token($token);check('token encoded as a single path segment',$service->received===rawurlencode($token));}
    $service->configureClient();check('identity calls have bounded connection and response time',$service->options===['connect'=>5,'total'=>15]);
}
