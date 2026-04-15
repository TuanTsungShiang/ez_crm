<?php

use App\Http\Controllers\Api\V1\MemberController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('members', [MemberController::class, 'search']);
        Route::post('members', [MemberController::class, 'store']);
        Route::get('members/{member:uuid}', [MemberController::class, 'show']);
        Route::put('members/{member:uuid}', [MemberController::class, 'update']);
        Route::delete('members/{member:uuid}', [MemberController::class, 'destroy']);
    });
