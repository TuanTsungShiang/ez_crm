<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;

class RegisterSchemaController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/auth/register/schema",
     *     operationId="getRegisterSchema",
     *     tags={"Auth"},
     *     summary="取得註冊表單 schema",
     *     description="提供前端動態渲染註冊表單所需的欄位定義、驗證規則與 OAuth 供應商清單",
     *     @OA\Response(response=200, description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="fields", type="array", @OA\Items(
     *                     @OA\Property(property="name", type="string", example="email"),
     *                     @OA\Property(property="label", type="string", example="電子郵件"),
     *                     @OA\Property(property="type", type="string", example="email"),
     *                     @OA\Property(property="required", type="boolean", example=true),
     *                     @OA\Property(property="rules", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="placeholder", type="string", nullable=true),
     *                     @OA\Property(property="hint", type="string", nullable=true)
     *                 )),
     *                 @OA\Property(property="links", type="object",
     *                     @OA\Property(property="terms", type="string"),
     *                     @OA\Property(property="privacy", type="string")
     *                 ),
     *                 @OA\Property(property="oauth_providers", type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     )
     * )
     */
    public function __invoke()
    {
        return $this->success([
            'fields' => [
                [
                    'name'        => 'name',
                    'label'       => '姓名',
                    'type'        => 'text',
                    'required'    => true,
                    'rules'       => ['required', 'string', 'max:100'],
                    'placeholder' => '請輸入真實姓名',
                ],
                [
                    'name'     => 'email',
                    'label'    => '電子郵件',
                    'type'     => 'email',
                    'required' => true,
                    'rules'    => ['required', 'email', 'unique:members,email', 'max:255'],
                ],
                [
                    'name'     => 'password',
                    'label'    => '密碼',
                    'type'     => 'password',
                    'required' => true,
                    'rules'    => ['required', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
                    'hint'     => '至少 8 字，需含大寫與數字',
                ],
                [
                    'name'     => 'password_confirmation',
                    'label'    => '確認密碼',
                    'type'     => 'password',
                    'required' => true,
                ],
                [
                    'name'     => 'phone',
                    'label'    => '手機',
                    'type'     => 'tel',
                    'required' => false,
                    'rules'    => ['nullable', 'string', 'max:20'],
                ],
                [
                    'name'     => 'agree_terms',
                    'label'    => '我同意服務條款與隱私政策',
                    'type'     => 'checkbox',
                    'required' => true,
                    'rules'    => ['accepted'],
                ],
            ],
            'links' => [
                'terms'   => config('app.url') . '/terms',
                'privacy' => config('app.url') . '/privacy',
            ],
            'oauth_providers' => ['google', 'github'],
        ]);
    }
}
