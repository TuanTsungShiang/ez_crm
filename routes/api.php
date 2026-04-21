<?php

use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\OAuthController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\RegisterSchemaController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\SendEmailOtpController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\Me\MeController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\TagController;
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

// 前台會員 Auth API（不需登入）
Route::prefix('v1/auth')->group(function () {
    Route::get('register/schema', RegisterSchemaController::class);
    Route::post('register', RegisterController::class);
    Route::post('verify/email/send', SendEmailOtpController::class);
    Route::post('verify/email', VerifyEmailController::class);
    Route::post('login', LoginController::class);
    Route::post('password/forgot', ForgotPasswordController::class);
    Route::post('password/reset', ResetPasswordController::class);

    // OAuth
    Route::get('oauth/{provider}/redirect', [OAuthController::class, 'redirect']);
    Route::get('oauth/{provider}/callback', [OAuthController::class, 'callback']);
});

// 前台會員 /me 管理自己資料（需登入 member guard）
Route::prefix('v1/me')
    ->middleware('auth:member')
    ->group(function () {
        Route::get('/', [MeController::class, 'show']);
        Route::put('/', [MeController::class, 'update']);
        Route::put('password', [MeController::class, 'updatePassword']);
        Route::post('logout', [MeController::class, 'logout']);
        Route::post('logout-all', [MeController::class, 'logoutAll']);
        Route::delete('/', [MeController::class, 'destroy']);
    });

Route::prefix('v1')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('members', [MemberController::class, 'search']);
        Route::post('members', [MemberController::class, 'store']);
        Route::get('members/{member:uuid}', [MemberController::class, 'show']);
        Route::put('members/{member:uuid}', [MemberController::class, 'update']);
        Route::delete('members/{member:uuid}', [MemberController::class, 'destroy']);

        // Groups
        Route::apiResource('groups', GroupController::class);

        // Tags
        Route::apiResource('tags', TagController::class);
    });
