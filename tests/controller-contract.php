<?php
// Production proxy controller with dependency-free, in-memory upstream doubles.
// This checks contract behavior, not Laravel routing or real upstream connectivity.
namespace App\Http\Controllers { class Controller {} }
namespace Illuminate\Http {
    class Request {
        public function __construct(private array $data=[]) {}
        public function all() {return $this->data;}
        public function input($key,$default=null) {return $this->data[$key]??$default;}
        public function bearerToken() {return 'test-token';}
    }
    class JsonResponse {public function __construct(public mixed $data, public int $status=200) {}}
}
namespace Contract {
    class Upstream {
        public function __construct(private mixed $data, private bool $failed=false, private int $status=200) {}
        public function failed() {return $this->failed;}
        public function status() {return $this->status;}
        public function object() {return json_decode(json_encode($this->data));}
    }
}
namespace App\Services {
    class NotesService {
        public mixed $response=null; public array $received=[];
        public function upload($data) {$this->received=$data; return $this->response;}
        public function download($data) {$this->received=$data; return $this->response;}
        public function sync($data) {$this->received=$data; return $this->response;}
        public function find($id,$user) {$this->received=['id'=>$id,'user_id'=>$user]; return $this->response;}
    }
}
namespace StellarSecurity\UserApiLaravel {
    class UserService {public mixed $response; public function token($token) {return $this->response;}}
}
namespace {
    function response() {return new class {function json($data,$status=200) {return new \Illuminate\Http\JsonResponse($data,$status);}};}
    require __DIR__.'/../app/Http/Controllers/V1/NotesController.php';
    function check($name,$condition) {if(!$condition) throw new \RuntimeException($name); echo "PASS: $name\n";}
    $notes=new \App\Services\NotesService();
    $user=new \StellarSecurity\UserApiLaravel\UserService();
    $user->response=new \Contract\Upstream(['token'=>['id'=>1,'tokenable_id'=>42]]);
    $controller=new \App\Http\Controllers\V1\NotesController($notes,$user);
    $request=new \Illuminate\Http\Request(['id'=>'note','user_id'=>999,'known_notes'=>['note'=>100],'notes'=>[
        ['id'=>'note','last_modified'=>'100','pinned'=>'false','favorite'=>'1'],
        ['id'=>'note','last_modified'=>50,'text'=>'older'],
    ]]);
    $notes->response=new \Contract\Upstream(['ok'=>true]);
    check('legacy upload response',$controller->upload($request)->data===['ok'=>true]);
    check('server authenticated identity wins',$notes->received['user_id']===42);
    check('legacy string booleans normalized',$notes->received['notes'][0]['pinned']===false && $notes->received['notes'][0]['favorite']===true);
    check('duplicate ids retain newest version',count($notes->received['notes'])===1 && $notes->received['notes'][0]['last_modified']===100);
    check('optional manifest forwarded intact',$notes->received['known_notes']===['note'=>100]);
    $notes->response=new \Contract\Upstream(['notes'=>[],'folders'=>[]]);
    check('empty download arrays retain their shape',$controller->download($request)->data===['notes'=>[],'folders'=>[]]);
    $notes->response=new \Contract\Upstream(null);
    check('missing note remains null',$controller->find($request)->data===null);
    check('missing find id is 400',$controller->find(new \Illuminate\Http\Request())->status===400);
    foreach(['upload','download','sync','find'] as $method) {
        $notes->response=null;
        check("$method handles unavailable upstream",$controller->$method($request)->status===502);
        $notes->response=new \Contract\Upstream(null,true);
        check("$method handles upstream failure",$controller->$method($request)->status===502);
        $user->response=null;
        check("$method handles unavailable token response",$controller->$method($request)->status===401);
        $user->response=new \Contract\Upstream(['token'=>['id'=>1,'tokenable_id'=>42]]);
    }
    $notes->response=new \Contract\Upstream(['ok'=>false,'note_ack_v1'=>false],true,409);
    check('new client conflict status forwarded',$controller->upload(new \Illuminate\Http\Request(['require_note_ack'=>true]))->status===409);
    check('old client upstream-error contract preserved',$controller->upload(new \Illuminate\Http\Request())->status===502);
    $notes->response=new \Contract\Upstream(['ok'=>true,'note_ack_v1'=>true]);
    check('new confirmation forwarded intact',$controller->upload(new \Illuminate\Http\Request(['require_note_ack'=>true]))->data===['ok'=>true,'note_ack_v1'=>true]);

    foreach (['upload','download','sync','find'] as $method) {
        foreach ([null, 0, -1, [], '1 OR 1=1'] as $invalidId) {
            $user->response=new \Contract\Upstream(['token'=>['id'=>1,'tokenable_id'=>$invalidId]]);
            check("$method rejects malformed authenticated identity",$controller->$method($request)->status===401);
        }
    }
    $user->response=new \Contract\Upstream(['token'=>['id'=>1,'tokenable_id'=>'42']]);
    check('legacy string token owner accepted',$controller->download($request)->status===200);
    check('array note id rejected without calling upstream',$controller->find(new \Illuminate\Http\Request(['id'=>['invalid']]))->status===400);

}
