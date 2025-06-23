<?php

namespace App\Http\Controllers\V1;

use App\Helpers\NoteHelper;
use App\Services\NotesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotesController
{

    private NotesService $notesService;

    private NoteHelper $noteHelper;

    public function __construct(NotesService $notesService, NoteHelper $noteHelper)
    {
        $this->notesService = $notesService;
        $this->noteHelper = $noteHelper;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function update(Request $request): JsonResponse
    {

        $user_token = $request->input('user_token');

        // add validation for user token?...

        // base64 encoded.
        $json_content_encoded = $request->input('json_content_encoded');

        $json_content_decoded = base64_decode($json_content_encoded);

        if(!$this->noteHelper->validateJsonString($json_content_decoded)) {
            return response()->json(['response_code' => 400]);
        }

        // validate token
        $noteResponse = $this->notesService->updateOrCreate(150, $json_content_encoded);

        if($noteResponse === null || !$noteResponse->ok()) {
            return response()->json(['response_code' => 400]);
        }

        return response()->json(['response_code' => 200]);

    }

}
