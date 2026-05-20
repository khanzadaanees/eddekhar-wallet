<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'stubs/payroll/*',
            'stubs/bank/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                $e->errors(),
            );
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $model = class_basename($e->getModel());

            return ApiResponse::error(
                "{$model} not found.",
                'RESOURCE_NOT_FOUND',
                404,
            );
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $previous = $e->getPrevious();

            if ($previous instanceof ModelNotFoundException) {
                $model = class_basename($previous->getModel());

                return ApiResponse::error(
                    "{$model} not found.",
                    'RESOURCE_NOT_FOUND',
                    404,
                );
            }

            return ApiResponse::error(
                'Resource not found.',
                'RESOURCE_NOT_FOUND',
                404,
            );
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (method_exists($e, 'render')) {
                $response = $e->render($request);

                if ($response !== null) {
                    return $response;
                }
            }

            if ($e instanceof HttpExceptionInterface) {
                if ($e->getStatusCode() === 404) {
                    return ApiResponse::error(
                        'Resource not found.',
                        'RESOURCE_NOT_FOUND',
                        404,
                    );
                }

                return ApiResponse::error(
                    $e->getMessage() ?: 'Request failed.',
                    'HTTP_ERROR',
                    $e->getStatusCode(),
                );
            }

            return ApiResponse::error(
                app()->isProduction()
                    ? 'An unexpected error occurred.'
                    : $e->getMessage(),
                'SERVER_ERROR',
                500,
            );
        });
    })->create();
