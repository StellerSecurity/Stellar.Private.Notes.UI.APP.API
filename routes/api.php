<?php

use App\Http\Controllers\V1\NotesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/notescontroller')->group(function () {
    Route::patch('/update', [NotesController::class, 'update']);
});
