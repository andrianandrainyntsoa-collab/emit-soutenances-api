<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérification du rôle et de l'autorisation du compte.
 *
 * Deux contrôles sont appliqués côté serveur :
 *   1. si des rôles sont fournis, le rôle de l'utilisateur figure
 *      parmi ceux admis pour la route ;
 *   2. les comptes étudiants et enseignants doivent avoir été
 *      autorisés par un administrateur (users.est_autorise) — la
 *      vérification est refaite à chaque requête protégée, pas
 *      seulement au moment du login.
 *
 * Sans rôle de route, ce middleware vérifie uniquement que le compte
 * authentifié possède un rôle et, si nécessaire, a été autorisé.
 */
class RoleMiddleware
{
    private const ROLES_EXEMPTES_AUTORISATION = ['administrateur', 'responsable'];

    public function handle(
        Request $request,
        Closure $next,
        string ...$roles
    ): Response {
        $user = $request->user();

        if (
            !$user
            || !$user->role
            || ($roles !== [] && !in_array($user->role->name, $roles, true))
        ) {
            return response()->json([
                'message' => 'Accès interdit.',
            ], 403);
        }

        if (!in_array($user->role->name, self::ROLES_EXEMPTES_AUTORISATION, true)
            && !$user->est_autorise) {
            return response()->json([
                'message' => 'Votre compte est en attente d\'autorisation par l\'administrateur.',
                'compte_en_attente' => true,
            ], 403);
        }

        return $next($request);
    }
}
