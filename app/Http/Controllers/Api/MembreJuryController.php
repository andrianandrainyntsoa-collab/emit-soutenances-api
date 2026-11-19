<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembreJury;
use Illuminate\Http\Request;

/**
 * Gestion fine des membres de jury.
 *
 * Conformité au livre :
 *   - RG03 : composition Président / Rapporteur / Examinateur(s).
 *   - Les contrôles métier (unicité du président, unicité du
 *     rapporteur, membre unique) sont délégués au JuryService via
 *     JuryController::ajouterMembre / retirerMembre.
 */
class MembreJuryController extends Controller
{
    /**
     * Liste des membres d'un jury.
     */
    public function index(Request $request, int $juryId)
    {
        $membres = MembreJury::with('enseignant.user')
            ->where('jury_id', $juryId)
            ->get();

        return response()->json($membres);
    }

    /**
     * Détail d'un membre.
     */
    public function show(MembreJury $membre)
    {
        return response()->json($membre->load(['enseignant.user', 'jury']));
    }
}
