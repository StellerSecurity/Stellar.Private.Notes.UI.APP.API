<?php
namespace App\Http\Controllers { class Controller {} }
namespace Illuminate\Http {
 class Request {
  public function __construct(public string $auth='A', public string $origin='http://localhost', public array $data=[]) {}
  public function bearerToken(){return $this->auth;}
  public function header($name){return $this->origin;}
  public function all(){return $this->data;}
 }
}
namespace StellarSecurity\UserApiLaravel {
 class UserService {
  public function token($token) {
   $id=['A'=>11,'A-desktop-2'=>11,'A-mobile-1'=>11,'A-mobile-2'=>11,'B'=>22,'malformed'=>['22']][$token]??null;
   return new class($id) {
    public function __construct(private mixed $id){}
    public function failed(){return $this->id===null;}
    public function object(){return (object)['token'=>(object)['id'=>1,'tokenable_id'=>$this->id]];}
   };
  }
}
}
namespace {
 $settings=['realtime.enabled'=>true,'realtime.endpoint'=>'https://test.webpubsub.azure.com','realtime.scope_key'=>'synthetic-test-key','realtime.origins'=>['http://localhost']];
 function config($key){global $settings;return $settings[$key]??null;}
 function response(){return new class {public function json($body,$status=200,$headers=[]){return (object)compact('body','status','headers');}};}
 require __DIR__.'/../app/Services/NotesRealtimeService.php';
 require __DIR__.'/../app/Http/Controllers/V1/NotesRealtimeController.php';
 function check($value,$label){if(!$value)throw new \RuntimeException($label);echo "PASS: $label\n";}
 $service=new class extends \App\Services\NotesRealtimeService {
  public array $ids=[];
  public function negotiate(string $id):array{$this->ids[]=$id;return ['enabled'=>true,'subject'=>self::channel($id,120,'synthetic-test-key')];}
 };
 $controller=new \App\Http\Controllers\V1\NotesRealtimeController;
 $users=new \StellarSecurity\UserApiLaravel\UserService;
 $a=$controller->negotiate(new \Illuminate\Http\Request('A','http://localhost',['user_id'=>22,'channel'=>'victim','roles'=>['*']]),$users,$service);
 $b=$controller->negotiate(new \Illuminate\Http\Request('B'),$users,$service);
 check($service->ids===['11','22'],'authenticated identity wins over forged user/channel/roles');
 check($a->body['subject']!==$b->body['subject'],'two users receive distinct notification addresses');
 check($a->headers['Cache-Control']==='no-store, private','negotiation response cannot be cached');
 foreach(['','unknown','malformed'] as $token){$r=$controller->negotiate(new \Illuminate\Http\Request($token),$users,$service);check($r->status===401,'invalid authentication rejected: '.($token?:'empty'));}
 $r=$controller->negotiate(new \Illuminate\Http\Request('A','https://attacker.invalid'),$users,$service);check($r->status===403,'unapproved origin rejected');
 check(count($service->ids)===2,'rejected callers never receive grants');
 $addresses=[];for($i=1;$i<=1000;$i++)$addresses[]=\App\Services\NotesRealtimeService::channel((string)$i,120,'synthetic-test-key');
 check(count(array_unique($addresses))===1000,'1000 users produce distinct addresses');
 check(\App\Services\NotesRealtimeService::channel('11',120,'synthetic-test-key')!==\App\Services\NotesRealtimeService::channel('11',180,'synthetic-test-key'),'expired address no longer receives subsequent-minute notifications');
 $subjects=[];
 foreach(['A','A-desktop-2','A-mobile-1','A-mobile-2'] as $deviceToken){
  $grant=$controller->negotiate(new \Illuminate\Http\Request($deviceToken),$users,$service);
  check($grant->status===200,'independent device session receives grant: '.$deviceToken);
  $subjects[]=$grant->body['subject'];
 }
 check(count(array_unique($subjects))===1,'two desktop and two mobile sessions share authenticated user destination');
 check($subjects[0]!==$b->body['subject'],'second account remains isolated from all four sessions');
 $invalid=$controller->negotiate(new \Illuminate\Http\Request('revoked-device'),$users,$service);
 check($invalid->status===401,'revoked device cannot renew connection');
 $remaining=$controller->negotiate(new \Illuminate\Http\Request('A-mobile-2'),$users,$service);
 check($remaining->status===200 && $remaining->body['subject']===$subjects[0],'revoked device does not invalidate another device grant');
 $settings['realtime.enabled']=false;
 $r=$controller->negotiate(new \Illuminate\Http\Request('A'),$users,$service);check($r->body===['enabled'=>false],'disabled service preserves polling fallback');
}
