<?php

use App\Http\Controllers\V1\LoginController;
use App\Http\Controllers\V1\NotesController;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->group(function () {

    Route::prefix('logincontroller')->group(function () {
        Route::post('/create', [LoginController::class, 'create']);
        Route::post('/auth', [LoginController::class, 'auth']);
    });


    Route::prefix('notescontroller')->group(function () {
        Route::post('/update', [NotesController::class, 'update']);
        Route::get('/dashboard', [NotesController::class, 'dashboard']);
    });
});
