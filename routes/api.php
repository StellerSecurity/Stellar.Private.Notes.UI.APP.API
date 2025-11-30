<?php

use App\Http\Controllers\V1\LoginController;
use App\Http\Controllers\V1\NotesController;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->group(function () {

    // Authentication & account-related endpoints
    // Throttled aggressively to slow down brute-force and abuse:
    // max 20 requests per minute per client (IP by default)
    Route::prefix('logincontroller')
        ->middleware('throttle:20,1')
        ->group(function () {
            Route::post('/create', [LoginController::class, 'create']);                     // Register / create account
            Route::post('/auth', [LoginController::class, 'auth']);                         // Login
            Route::patch('/updateEak', [LoginController::class, 'updateEak']);              // Attach/rotate EAK for zero-knowledge apps
            Route::post('/sendresetpasswordlink', [LoginController::class, 'sendresetpasswordlink']); // Send password reset link
            Route::post('/resetpasswordupdate', [LoginController::class, 'resetpasswordupdate']);     // Complete password reset
        });

    // Notes API – E2EE payloads only (server never sees plaintext)
    // Throttled, but more relaxed to allow sync/usage:
    // max 200 requests per minute per client
    Route::prefix('notescontroller')
        ->middleware('throttle:200,1')
        ->group(function () {
            Route::post('/upload', [NotesController::class, 'upload']);         // Upload encrypted notes from client
            Route::post('/sync-plan', [NotesController::class, 'sync']);        // Get sync plan (what to push/pull)
            Route::post('/download', [NotesController::class, 'download']);     // Download encrypted notes for this user
            Route::post('/find', [NotesController::class, 'find']);             // Search/filter notes (still encrypted payloads)
        });
});
