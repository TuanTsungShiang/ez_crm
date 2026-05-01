<?php

namespace App\Exceptions;

use App\Enums\ApiCode;
use App\Exceptions\Points\InsufficientPointsException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'code'    => ApiCode::NOT_FOUND,
                    'message' => 'Resource not found',
                ], 404);
            }

            if ($e instanceof NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'code'    => ApiCode::ENDPOINT_NOT_FOUND,
                    'message' => 'Endpoint not found',
                ], 404);
            }

            if ($e instanceof InsufficientPointsException) {
                return response()->json([
                    'success' => false,
                    'code'    => ApiCode::INSUFFICIENT_POINTS,
                    'message' => 'Insufficient points balance',
                ], 422);
            }
        }

        return parent::render($request, $e);
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code'    => ApiCode::NO_TOKEN,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return redirect()->guest($exception->redirectTo() ?? route('login'));
    }
}
