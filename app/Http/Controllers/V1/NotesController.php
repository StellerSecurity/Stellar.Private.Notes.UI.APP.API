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
    public function upload(Request $request): JsonResponse
    {

        $token = $request->input('token');

        $user = $this->userService->token($token)->object();

        if(!isset($user->token->id)) {
            return response()->json(null, 400);
        }

        $user_id = $user->token->id;

        $data = $request->all();
        $data['user_id'] = $user_id;

        $upload = $this->notesService->upload($data);
        return response()->json($upload->object());
    }

    public function sync(Request $request): JsonResponse
    {

        $token = $request->input('token');

        $user = $this->userService->token($token)->object();

        if(!isset($user->token->id)) {
            return response()->json(null, 400);
        }

        $user_id = $user->token->id;

        $data = $request->all();
        $data['user_id'] = $user_id;

        $sync = $this->notesService->sync($data);
        return response()->json($sync->object());
    }

    public function find(Request $request): JsonResponse
    {

        $token = $request->input('token');

        $user = $this->userService->token($token)->object();

        if(!isset($user->token->id)) {
            return response()->json(null, 400);
        }

        $user_id = $user->token->id;

        $note = $this->notesService->find($request->input('id'), $user_id);
        return response()->json($note->object());
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function download(Request $request): JsonResponse
    {

        $token = $request->input('token');

        $user = $this->userService->token($token)->object();

        if(!isset($user->token->id)) {
            return response()->json(null, 400);
        }

        $user_id = $user->token->id;

        $data = $request->all();
        $data['user_id'] = $user_id;

        $download = $this->notesService->download($data);
        return response()->json($download->object());

    }

}
