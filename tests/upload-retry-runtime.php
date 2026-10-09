<?php
// Real Laravel HTTP client with synthetic credentials and faked outbound requests.
if (PHP_SAPI !== 'cli') exit(2);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../app/Services/NotesService.php';

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

putenv('BASE_URL_NOTES_API=http://notes.invalid/api/');
putenv('APPSETTING_API_USERNAME_STELLAR_NOTES_API=synthetic-user');
putenv('APPSETTING_API_PASSWORD_STELLAR_NOTES_API=synthetic-password');
$service = new App\Services\NotesService();
$checks = 0;
function checkRetry(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo 'PASS upload retry: '.$label.PHP_EOL;
}
foreach ([true, false] as $modern) {
    Http::swap(new Factory());
    Http::preventStrayRequests();
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        return Http::response(['ok'=>false, 'note_ack_v1'=>false], 409);
    });
    $response = $service->upload(['notes'=>[], 'require_note_ack'=>$modern]);
    checkRetry($attempts === 1, ($modern ? 'modern' : 'legacy').' conflict is not retried');
    checkRetry($modern ? $response?->status() === 409 : $response === null, 'existing conflict/error response is preserved');
}

foreach (['transient', 'unavailable', 'connection'] as $scenario) {
    Http::swap(new Factory());
    Http::preventStrayRequests();
    $attempts = 0;
    Http::fake(function () use (&$attempts, $scenario) {
        $attempts++;
        if ($scenario === 'connection') throw new ConnectionException('Synthetic connection failure');
        return $scenario === 'transient' && $attempts === 2
            ? Http::response(['ok'=>true], 200) : Http::response([], 503);
    });
    $response = $service->upload(['notes'=>[]]);
    checkRetry($attempts === ($scenario === 'transient' ? 2 : 3), $scenario.' keeps bounded retries');
    checkRetry($scenario === 'transient' ? $response?->status() === 200 : $response === null, $scenario.' preserves success/failure behavior');
}
echo 'Upload retry runtime: '.$checks.' checks passed'.PHP_EOL;
