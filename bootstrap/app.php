<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'jwt.auth' => \App\Http\Middleware\JwtAuthMiddleware::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions) {
        // Capturar errores de autenticación
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthorized'], 401);
        });

        // Capturar errores de JWT
        $exceptions->render(function (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e, Request $request) {
            return response()->json(['message' => 'Token expired'], 401);
        });

        $exceptions->render(function (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e, Request $request) {
            return response()->json(['message' => 'Token invalid'], 401);
        });

        // Capturar cualquier otro error de tipo HTTP (404, 403, etc.)
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Error',
                'status' => $e->getStatusCode(),
            ], $e->getStatusCode());
        });

        // Capturar cualquier otro error (por ejemplo errores 500)
        $exceptions->render(function (Throwable $e, Request $request) {
            return response()->json([
                'message' => 'Internal Server Error',
                'error' => $e->getMessage(),
            ], 500);
        });
    })
    ->create();
