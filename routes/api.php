<?php

use App\Http\Controllers\V1\LoginController;
use App\Http\Controllers\V1\NotesController;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->group(function () {

    Route::prefix('logincontroller')->group(function () {
        Route::post('/create', [LoginController::class, 'create']);
        Route::post('/auth', [LoginController::class, 'auth']);

        Route::post('/sendresetpasswordlink', [LoginController::class, 'sendresetpasswordlink']);
        Route::post('/resetpasswordupdate', [LoginController::class, 'resetpasswordupdate']);
    });


    Route::prefix('notescontroller')->group(function () {
        Route::post('/upload', [NotesController::class, 'upload']);
        Route::post('/sync-plan', [NotesController::class, 'sync']);
        Route::get('/download', [NotesController::class, 'download']);
        Route::get('/find', [NotesController::class, 'find']);
    });
});
