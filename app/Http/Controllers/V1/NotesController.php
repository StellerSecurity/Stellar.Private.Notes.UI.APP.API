<?php

namespace App\Http\Controllers\V1;

use App\Helpers\NoteHelper;
use App\Services\NotesService;
use App\Services\UserService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotesController
{

    private NotesService $notesService;

    private NoteHelper $noteHelper;

    private UserService $userService;

    private int $user_id = 150;

    public function __construct(NotesService $notesService, NoteHelper $noteHelper, UserService $userService)
    {
        $this->notesService = $notesService;
        $this->noteHelper = $noteHelper;
        $this->userService = $userService;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws ConnectionException
     */
    public function update(Request $request): JsonResponse
    {

        $user_token = $request->input('user_token');

        if(empty($user_token)) {
            return response()->json(['response_code' => 400]);
        }

        $token = $this->userService->token($user_token)->object();

        if($token->response_code !== 200) {
            return response()->json(['response_code' => 400]);
        }

        $user_id = $token->token->tokenable_id;

        // add validation for user token?...

        // base64 encoded.
        $json_content_encoded = $request->input('json_content_encoded');

        $json_content_decoded = base64_decode($json_content_encoded);

        if(!$this->noteHelper->validateJsonString($json_content_decoded)) {
            return response()->json(['response_code' => 400]);
        }

        $noteResponse = $this->notesService->updateOrCreate($user_id, $json_content_encoded);

        if($noteResponse === null || !$noteResponse->ok()) {
            return response()->json(['response_code' => 400]);
        }

        return response()->json(['response_code' => 200]);

    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function dashboard(Request $request): JsonResponse
    {

        $user_token = $request->input('user_token');

        if(empty($user_token)) {
            return response()->json(['response_code' => 400]);
        }

        $token = $this->userService->token($user_token)->object();

        if($token->response_code !== 200) {
            return response()->json(['response_code' => 400]);
        }

        $user_id = $token->token->tokenable_id;

        $notes = $this->notesService->getNotes($user_id);

        return response()->json($notes->object());

    }

}
