<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'compte_autorise' => RoleMiddleware::class,
        ]);

        // CORRECTION « redirection vers une route inexistante » : par
        // défaut, Laravel redirige les requêtes non authentifiées vers
        // la route web « login » — qui n'existe pas dans ce projet
        // (l'authentification passe par POST /api/login). Cette
        // redirection déclenchait une RouteNotFoundException (500) au
        // lieu d'un 401 JSON propre, en particulier lors de
        // l'ouverture d'un PDF avec un token expiré/invalide. En
        // renvoyant null, les routes api/* reçoivent un 401 JSON
        // (grâce à shouldRenderJsonWhen ci-dessous).
        $middleware->redirectGuestsTo(fn (Request $request) => null);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

    
