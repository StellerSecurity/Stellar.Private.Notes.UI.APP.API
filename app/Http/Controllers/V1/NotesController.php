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
        $data            = $this->normalizeNotesSyncPayload($request->all());
        $data['user_id'] = $user_id;

        $upload = $this->notesService->upload($data);

        if ($upload->failed()) {
            return response()->json(['response_message' => 'Notes service unavailable'], 502);
        }

        return response()->json($this->normalizeNotesSyncPayload($upload->object()));
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
        $data            = $this->normalizeNotesSyncPayload($request->all());
        $data['user_id'] = $user_id;

        $sync = $this->notesService->sync($data);

        if ($sync->failed()) {
            return response()->json(['response_message' => 'Notes service unavailable'], 502);
        }

        return response()->json($this->normalizeNotesSyncPayload($sync->object()));
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

        return response()->json($this->normalizeNotesSyncPayload($note->object()));
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
        $data            = $this->normalizeNotesSyncPayload($request->all());
        $data['user_id'] = $user_id;

        $download = $this->notesService->download($data);

        if ($download->failed()) {
            return response()->json(['response_message' => 'Notes service unavailable'], 502);
        }

        return response()->json($this->normalizeNotesSyncPayload($download->object()));
    }

    /**
     * Normalize note + folder sync payloads recursively so the UI API and Base API
     * stay aligned across legacy and newer clients.
     */
    private function normalizeNotesSyncPayload(mixed $value): mixed
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
            if (in_array($key, ['pinned', 'favorite', 'deleted', 'protected', 'auto_wipe'], true)) {
                $normalized = filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalized !== null) {
                    $value[$key] = $normalized;
                    continue;
                }
            }

            if (in_array($key, ['since', 'last_modified'], true) && is_numeric($item)) {
                $value[$key] = (int) $item;
                continue;
            }

            if ($key === 'folder_id' && ($item === '' || $item === false)) {
                $value[$key] = null;
                continue;
            }

            if ($key === 'folder' && $item === null) {
                $value[$key] = '';
                continue;
            }

            $value[$key] = $this->normalizeNotesSyncPayload($item);
        }

        if (array_key_exists('notes', $value) && is_array($value['notes'])) {
            $value['notes'] = $this->dedupeByIdAndLastModified($value['notes'], 'id');
        }

        if (array_key_exists('folders', $value) && is_array($value['folders'])) {
            $value['folders'] = $this->dedupeByIdAndLastModified($value['folders'], 'id');
        }

        return $value;
    }

    /**
     * Keep only one record per id and let the highest last_modified win.
     * If last_modified is equal, the last occurrence wins deterministically.
     */
    private function dedupeByIdAndLastModified(array $items, string $idKey): array
    {
        $deduped = [];

        foreach ($items as $item) {
            if ($item instanceof JsonSerializable) {
                $item = $item->jsonSerialize();
            }

            if (is_object($item)) {
                $item = (array) $item;
            }

            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item[$idKey] ?? ''));
            if ($id === '') {
                continue;
            }

            $currentLastModified = (int) ($item['last_modified'] ?? 0);
            $existing = $deduped[$id] ?? null;
            $existingLastModified = (int) (($existing['last_modified'] ?? 0));

            if ($existing === null || $currentLastModified >= $existingLastModified) {
                $deduped[$id] = $item;
            }
        }

        return array_values($deduped);
    }
}
