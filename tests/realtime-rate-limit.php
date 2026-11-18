<?php
if (PHP_SAPI !== 'cli' || getenv('STELLAR_RUNTIME_TEST') !== '1') {
    fwrite(STDERR, "Run only in an isolated runtime-test process.\n"); exit(2);
}
$autoload = getenv('STELLAR_REVIEWED_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
require $autoload;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpFoundation\Response;

function checkRate(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
function rateRequest(string $uri): Request {
    $request = Request::create($uri, 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.19']);
    $request->setRouteResolver(fn () => new Route('POST', ltrim($uri, '/'), fn () => null));
    return $request;
}
$routes = file_get_contents(dirname(__DIR__).'/routes/api.php');
$start = strpos($routes, "Route::post('v1/notescontroller/realtime'");
checkRate($start !== false, 'realtime route exists');
preg_match("/throttle:(\d+),(\d+)(?:,([^']+))?/", substr($routes, $start), $match);
$maximum = (int)($match[1] ?? 0); $minutes = (int)($match[2] ?? 0); $prefix = $match[3] ?? '';
checkRate($maximum === 20 && $minutes === 1, 'realtime retains its 20 per-minute limit');
$middleware = new ThrottleRequests(new RateLimiter(new Repository(new ArrayStore())));
$next = fn () => new Response('', 200);
$notes = rateRequest('/api/v1/notescontroller/download');
$realtime = rateRequest('/api/v1/notescontroller/realtime');
for ($i = 0; $i < 20; $i++) $middleware->handle($notes, $next, 200, 1);
$first = $middleware->handle($realtime, $next, $maximum, $minutes, $prefix);
checkRate($first->getStatusCode() === 200, '20 sync requests cannot consume the realtime allowance');
for ($i = 1; $i < 20; $i++) $middleware->handle($realtime, $next, $maximum, $minutes, $prefix);
try {
    $middleware->handle($realtime, $next, $maximum, $minutes, $prefix);
    throw new RuntimeException('Expected realtime request 21 to be throttled');
} catch (ThrottleRequestsException $error) {
    checkRate($error->getStatusCode() === 429, 'request 21 is still rejected');
    checkRate((int)($error->getHeaders()['Retry-After'] ?? 0) > 0, '429 includes Retry-After');
}
checkRate($middleware->handle($notes, $next, 200, 1)->getStatusCode() === 200, 'realtime exhaustion does not block note downloads');
