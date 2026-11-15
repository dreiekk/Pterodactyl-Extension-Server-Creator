<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use ServerCreator\Http\Controllers\ServerController;

Route::get('/', [ServerController::class, 'index']);
Route::put('/', [ServerController::class, 'update']);
Route::put('/game', [ServerController::class, 'game']);
Route::get('/allocations', [ServerController::class, 'allocations']);
Route::put('/allocations', [ServerController::class, 'updateAllocations']);
Route::delete('/', [ServerController::class, 'destroy']);
