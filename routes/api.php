<?php

use App\Http\Controllers\V1\NotesController;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->group(function () {

    Route::prefix('notescontroller')->group(function () {
        Route::post('/update', [NotesController::class, 'update']);
    });

    Route::prefix(' logincontroller')->group(function () {
        Route::controller(\App\Http\Controllers\V1\LoginController::class)->group(function () {
            Route::post('/login', 'auth');
            Route::post('/create', 'create');
            Route::post('/sendresetpasswordlink', 'sendresetpasswordlink');
        });
    });

});
