<?php
namespace App\Http\Controllers\V1;
use App\Http\Controllers\Controller;
use App\Services\NotesRealtimeService;
use Illuminate\Http\Request;
use StellarSecurity\UserApiLaravel\UserService;

class NotesRealtimeController extends Controller
{
    public function negotiate(Request $request, UserService $users, NotesRealtimeService $realtime)
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];
        if (!$realtime->enabled()) return response()->json(['enabled' => false], 200, $headers);
        $origin = $request->header('Origin');
        if (!is_string($origin) || !in_array($origin, config('realtime.origins'), true)) return response()->json(null, 403, $headers);
        $token = $request->bearerToken();
        if (!is_string($token) || $token === '') return response()->json(null, 401, $headers);
        try {
            $result = $users->token($token);
            $user = $result !== null && !$result->failed() ? $result->object() : null;
            $id = $user->token->tokenable_id ?? null;
            if (!isset($user->token->id) || (!is_int($id) && !is_string($id))
                || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) return response()->json(null, 401, $headers);
            return response()->json($realtime->negotiate((string)$id), 200, $headers);
        } catch (\Throwable $error) {
            return response()->json(['enabled' => false], 503, $headers);
        }
    }
}
