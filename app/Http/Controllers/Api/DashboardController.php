<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;

/**
 * Tableau de bord (cahier des charges 3.5.3).
 *
 * Conformité au livre :
 *   - Présentation synthétique des informations et indicateurs
 *     utiles au suivi des activités liées aux soutenances.
 *   - Les indicateurs sont adaptés au rôle de l'utilisateur :
 *     administrateur / responsable (vue globale), enseignant (ses
 *     jurys et évaluations), étudiant (sa soutenance).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * Indicateurs synthétiques selon le rôle de l'utilisateur.
     */
    public function index(Request $request)
    {
        $utilisateur = $request->user()->loadMissing('role');

        $reponse = [
            'role' => $utilisateur->role->name,
        ];

        switch ($utilisateur->role->name) {
            case 'administrateur':
            case 'responsable':
                $reponse['statistiques'] = $this->dashboard->statistiquesGlobales();
                break;

            case 'enseignant':
                $enseignant = $utilisateur->enseignant;

                if (!$enseignant) {
                    return response()->json(['message' => 'Profil enseignant introuvable.'], 403);
                }

                $reponse['statistiques'] = $this->dashboard->statistiquesEnseignant($enseignant->id);
                break;

            case 'etudiant':
                $etudiant = $utilisateur->etudiant;

                if (!$etudiant) {
                    return response()->json(['message' => 'Profil étudiant introuvable.'], 403);
                }

                $reponse['statistiques'] = $this->dashboard->statistiquesEtudiant($etudiant->id);
                break;

            default:
                return response()->json(['message' => 'Rôle non reconnu.'], 403);
        }

        return response()->json($reponse);
    }
}
