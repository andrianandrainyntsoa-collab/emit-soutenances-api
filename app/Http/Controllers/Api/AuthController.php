<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Rôles dont le compte doit avoir été autorisé par un
     * administrateur avant toute connexion.
     */
    private const ROLES_SOUMIS_A_AUTORISATION = ['etudiant', 'enseignant'];

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with('role')->where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
        }

        // L'autorisation du compte est vérifiée côté backend : un
        // compte étudiant ou enseignant non autorisé ne peut pas se
        // connecter (la règle vaut pour le web et pour le mobile).
        if (in_array($user->role?->name, self::ROLES_SOUMIS_A_AUTORISATION, true)
            && !$user->est_autorise) {
            return response()->json([
                'message' => 'Votre compte est en attente d\'autorisation par l\'administrateur. '
                    .'Vous ne pouvez pas encore accéder à votre espace.',
                'compte_en_attente' => true,
            ], 403);
        }

        $token = $user->createToken('gestion-soutenance')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie.',
            'token' => $token,
            'user' => $user->load('role', 'etudiant.promotion', 'enseignant'),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Déconnexion réussie.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(
            $request->user()->load('role', 'etudiant.promotion', 'enseignant')
        );
    }
}
