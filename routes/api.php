<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EditorManuscriptController;
use App\Http\Controllers\ManuscriptController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/manuscripts', [ManuscriptController::class, 'index']);
    Route::post('/manuscripts', [ManuscriptController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/manuscripts/{manuscript}', [ManuscriptController::class, 'show']);

    Route::get('/editor/manuscripts', [EditorManuscriptController::class, 'index']);
    Route::patch('/editor/manuscripts/{manuscript}/status', [EditorManuscriptController::class, 'updateStatus']);
});
