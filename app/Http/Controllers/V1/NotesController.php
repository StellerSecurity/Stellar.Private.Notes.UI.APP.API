<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\NotesService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonSerializable;
use StellarSecurity\UserApiLaravel\UserService;

class NotesController extends Controller
{
    private NotesService $notesService;
    private UserService $userService;

    public function __construct(NotesService $notesService, UserService $userService)
    {
        $this->notesService = $notesService;
        $this->userService  = $userService;
    }

    /**
     * @throws ConnectionException
     */
    public function upload(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        $userResponse = $this->userService->token($token);

        if ($userResponse->failed()) {
            return response()->json(null, 401);
        }

        $user = $userResponse->object();

        if (! $user || ! isset($user->token->id)) {
            return response()->json(null, 401);
        }

        $user_id         = $user->token->tokenable_id;
        $data            = $this->normalizePinnedFavoriteFlags($request->all());
        $data['user_id'] = $user_id;

        $upload = $this->notesService->upload($data);

        if ($upload->failed()) {
            return response()->json(['response_message' => 'Notes service unavailable'], 502);
        }

        return response()->json($this->normalizePinnedFavoriteFlags($upload->object()));
    }

    public function sync(Request $request): JsonResponse
    {
        $token        = $request->bearerToken();
        $userResponse = $this->userService->token($token);

        if ($userResponse->failed()) {
            return response()->json(null, 401);
        }

        $user = $userResponse->object();

        if (! isset($user->token->id)) {
            return response()->json(null, 401);
        }

        $user_id         = $user->token->tokenable_id;
        $data            = $this->normalizePinnedFavoriteFlags($request->all());
        $data['user_id'] = $user_id;

        $sync = $this->notesService->sync($data);

        if ($sync->failed()) {
            return response()->json(['response_message' => 'Notes service unavailable'], 502);
        }

        return response()->json($this->normalizePinnedFavoriteFlags($sync->object()));
    }

    public function find(Request $request): JsonResponse
    {
        $token        = $request->bearerToken();
        $userResponse = $this->userService->token($token);

        if ($userResponse->failed()) {
            return response()->json(null, 401);
        }

        $user = $userResponse->object();

        if (! isset($user->token->id)) {
            return response()->json(null, 401);
        }

        $noteId = $request->input('id');
        if ($noteId === null) {
            return response()->json(['response_message' => 'Note id missing'], 400);
        }

        $user_id = $user->token->tokenable_id;

        $note = $this->notesService->find($noteId, $user_id);

        if ($note->failed()) {
            return response()->json(['response_message' => 'Notes service unavailable'], 502);
        }

        return response()->json($this->normalizePinnedFavoriteFlags($note->object()));
    }

    public function download(Request $request): JsonResponse
    {
        $token        = $request->bearerToken();
        $userResponse = $this->userService->token($token);

        if ($userResponse->failed()) {
            return response()->json(['response_message' => 'Token not found'], 401);
        }

        $user = $userResponse->object();

        if (! isset($user->token->id)) {
            return response()->json(['response_message' => 'Token not found'], 401);
        }

        $user_id         = $user->token->tokenable_id;
        $data            = $this->normalizePinnedFavoriteFlags($request->all());
        $data['user_id'] = $user_id;

        $download = $this->notesService->download($data);

        if ($download->failed()) {
            return response()->json(['response_message' => 'Notes service unavailable'], 502);
        }

        return response()->json($this->normalizePinnedFavoriteFlags($download->object()));
    }

    /**
     * Normalize pinned/favorite flags recursively so the UI API and Base API use
     * consistent boolean values even when clients send string or numeric forms.
     */
    private function normalizePinnedFavoriteFlags(mixed $value): mixed
    {
        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (in_array($key, ['pinned', 'favorite'], true)) {
                $normalized = filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalized !== null) {
                    $value[$key] = $normalized;
                    continue;
                }
            }

            $value[$key] = $this->normalizePinnedFavoriteFlags($item);
        }

        return $value;
    }
}
