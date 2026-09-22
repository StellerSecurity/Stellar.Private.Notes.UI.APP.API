<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

/** Optional hints only. Never carries note content, IDs or app authentication tokens. */
class NotesRealtimeService
{
    public static function channel(string $userId, int $now, string $key): string
    {
        if ($key === '' || !ctype_digit($userId) || (int)$userId < 1) throw new \InvalidArgumentException('Invalid realtime identity');
        // Tokens expiring does not necessarily disconnect an existing WebSocket.
        // Publishing only to this minute's address bounds access even for a hostile client.
        return hash_hmac('sha256', 'notes:'.intdiv($now, 60).':'.$userId, $key);
    }
    public function enabled(): bool
    {
        return config('realtime.enabled') === true
            && preg_match('~^https://[a-z0-9-]+\.webpubsub\.azure\.com$~D', (string)config('realtime.endpoint')) === 1
            && config('realtime.scope_key') !== '';
    }
    private function identityToken(): string
    {
        $endpoint = (string)config('realtime.identity_endpoint');
        $header = (string)config('realtime.identity_header');
        if ($endpoint === '' || $header === '') throw new \RuntimeException('Managed identity unavailable');
        // Endpoint comes solely from Azure App Service settings, never from a request.
        $result = Http::withoutRedirecting()->connectTimeout(1)->timeout(3)
            ->withHeaders(['X-IDENTITY-HEADER' => $header])->get($endpoint, [
                'resource' => 'https://webpubsub.azure.com', 'api-version' => '2019-08-01',
            ])->throw()->json();
        if (!is_string($result['access_token'] ?? null)) throw new \RuntimeException('Managed identity unavailable');
        return $result['access_token'];
    }
    private function request()
    {
        return Http::withoutRedirecting()->connectTimeout(1)->timeout(3)->withToken($this->identityToken());
    }
    public function negotiate(string $userId): array
    {
        if (!$this->enabled()) return ['enabled' => false];
        $now = time();
        $expires = (intdiv($now, 60) + 1) * 60;
        // Avoid issuing a grant that expires during network transit.
        if ($expires - $now < 5) return ['enabled' => false, 'retry_after_ms' => 5000];
        $endpoint = config('realtime.endpoint');
        $channel = self::channel($userId, $now, config('realtime.scope_key'));
        $url = $endpoint.'/api/hubs/notes/:generateToken?'.http_build_query([
            'api-version' => '2024-01-01', 'userId' => $channel, 'minutesToExpire' => 1,
        ]);
        // No roles or groups: clients cannot subscribe to others or publish to clients.
        $result = $this->request()->post($url)->throw()->json();
        if (!is_string($result['token'] ?? null)) throw new \RuntimeException('Realtime unavailable');
        return ['enabled' => true, 'expires_at' => $expires * 1000,
            'url' => str_replace('https://', 'wss://', $endpoint).'/client/hubs/notes?access_token='.rawurlencode($result['token'])];
    }
    public function changed(string $userId): void
    {
        if (!$this->enabled()) return;
        try {
            $channel = self::channel($userId, time(), config('realtime.scope_key'));
            $this->request()->withBody('{"type":"notes.changed"}', 'application/json')
                ->post(config('realtime.endpoint').'/api/hubs/notes/users/'.$channel.'/:send?api-version=2024-01-01')->throw();
        } catch (\Throwable $error) {
            // Notification failure must never fail an already successful note mutation.
            // Do not log exception URLs/headers because they may contain credentials.
        }
    }
}
