<?php
namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

class UpstreamServiceErrors
{
    public function render(Request $request, RequestException|ConnectionException $error)
    {
        if ($error instanceof ConnectionException) return $this->unavailable();
        $status = $error->response->status();
        // NotesService catches its own upstream failures. An uncaught Notes
        // request error therefore comes from the authenticated token lookup.
        if (in_array($status, [401, 403], true)
            || ($status === 404 && $request->is('api/v1/notescontroller/*'))) {
            return response()->json(['response_code'=>401, 'response_message'=>'Authentication failed'], 401, ['Cache-Control'=>'no-store']);
        }
        if ($status >= 500 || in_array($status, [408, 429], true)) return $this->unavailable();
        // Preserve the failure category without exposing an upstream body,
        // URLs, service credentials or a provider stack trace.
        $status = $status >= 400 && $status < 500 ? $status : 502;
        return response()->json(['response_code'=>$status, 'response_message'=>'Request could not be completed'], $status, ['Cache-Control'=>'no-store']);
    }

    private function unavailable()
    {
        return response()->json(['response_code'=>502, 'response_message'=>'Service temporarily unavailable'], 502, ['Cache-Control'=>'no-store']);
    }
}
