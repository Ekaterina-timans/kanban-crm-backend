<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Broadcasting\BroadcastException;

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

    protected $dontReport = [
        BroadcastException::class,
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

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // Для API-запросов возвращаем JSON 401
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    public function render($request, Throwable $exception)
    {
        Log::error('Exception caught', [
            'exception' => $exception,
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
        return parent::render($request, $exception);
    }
}
