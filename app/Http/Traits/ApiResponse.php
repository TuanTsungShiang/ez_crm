<?php

namespace App\Http\Traits;

use App\Enums\ApiCode;
use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success($data, string $code = ApiCode::OK, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code'    => $code,
            'data'    => $data,
        ], $status);
    }

    protected function created($data): JsonResponse
    {
        return $this->success($data, ApiCode::CREATED, 201);
    }

    protected function error(string $code, string $message, int $status, array $errors = []): JsonResponse
    {
        $response = [
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }
}
