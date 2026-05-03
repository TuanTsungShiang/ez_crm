<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * @OA\Info(
 *     title="EZ CRM API",
 *     version="1.0.0",
 *     description="EZ CRM 系統 API 文件",
 *
 *     @OA\Contact(email="admin@ezcrm.local")
 * )
 *
 * @OA\Server(
 *     url="/api/v1",
 *     description="API V1"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="Token",
 *     description="Admin / User Bearer Token（auth:sanctum）"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="memberAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="Token",
 *     description="Member Bearer Token（auth:member）— 前台會員登入後取得"
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
