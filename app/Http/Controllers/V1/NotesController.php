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
        $upload = $this->notesService->upload($request->all());
        return response()->json($upload->object());
    }

    public function sync(Request $request): JsonResponse
    {
        $sync = $this->notesService->sync($request->all());
        return response()->json($sync->object());
    }

    public function find(Request $request): JsonResponse
    {
        $note = $this->notesService->find($request->input('id'));
        return response()->json($note->object());
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function download(Request $request): JsonResponse
    {

        $download = $this->notesService->download($request->all());
        return response()->json($download->object());

    }

}
