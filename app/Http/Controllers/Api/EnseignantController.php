<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enseignant;
use App\Services\AuditService;
use Illuminate\Http\Request;

/**
 * Consultation des enseignants (BF03/BF04).
 *
 * Règles de correction :
 *   - Les enseignants s'inscrivent eux-mêmes : l'administration ne
 *     crée plus de compte enseignant, ne modifie plus leurs
 *     informations personnelles et ne gère plus matricule / grade.
 *   - Les actions admises : rechercher, consulter, autoriser le
 *     compte (via /users/{id}/autoriser) et supprimer selon les
 *     règles métier.
 */
class EnseignantController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Liste des enseignants avec filtres.
     */
    public function index(Request $request)
    {
        $query = Enseignant::with(['user', 'membresJury.jury'])
            ->join('users', 'users.id', '=', 'enseignants.user_id')
            ->orderBy('users.name')
            ->select('enseignants.*', 'users.est_autorise');

        if ($request->filled('recherche')) {
            $terme = '%' . $request->string('recherche')->toString() . '%';
            $query->where(function ($q) use ($terme) {
                $q->whereLike('specialite', $terme)
                    ->orWhereLike('users.name', $terme)
                    ->orWhereLike('users.email', $terme);
            });
        }

        if ($request->filled('specialite')) {
            $query->whereLike('specialite', '%' . $request->string('specialite')->toString() . '%');
        }

        return response()->json(
            $query->paginate(min(max($request->integer('per_page', 20), 1), 100))
        );
    }

    /**
     * Détail d'un enseignant (jurys, évaluations).
     */
    public function show(Enseignant $enseignant)
    {
        return response()->json(
            $enseignant->load([
                'user', 'membresJury.jury.session',
                'evaluations.soutenance.etudiant.user',
            ])
        );
    }

    /**
     * Suppression d'un enseignant sans participation au processus.
     */
    public function destroy(Enseignant $enseignant)
    {
        $participe = $enseignant->evaluations()->exists()
            || $enseignant->membresJury()->exists();

        if ($participe) {
            return response()->json([
                'message' => 'Cet enseignant participe déjà au processus (jurys ou évaluations) : suppression impossible.',
            ], 409);
        }

        $nom = $enseignant->user->name;
        $enseignant->user->delete(); // cascade sur enseignants

        $this->audit->enregistrer(
            'SUPPRESSION_ENSEIGNANT',
            'enseignants',
            $enseignant->id,
            "Suppression de l'enseignant {$nom}."
        );

        return response()->json(['message' => 'Enseignant supprimé.']);
    }
}
