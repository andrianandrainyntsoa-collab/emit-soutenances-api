<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisponibiliteJury;
use App\Models\Enseignant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestion des disponibilités des enseignants (membres du jury).
 *
 * Conformité au livre :
 *   - BF04 : gérer les membres des jurys, salles, disponibilités et
 *     créneaux.
 *   - PlanificationService : un créneau n'est valide pour un jury
 *     que si chaque membre possède une disponibilité couvrant
 *     entièrement la plage.
 */
class DisponibiliteJuryController extends Controller
{
    /**
     * Liste des disponibilités (tous enseignants) — administration.
     */
    public function index(Request $request)
    {
        $query = DisponibiliteJury::with(['enseignant.user'])
            ->orderBy('date_disponible')
            ->orderBy('heure_debut');

        if ($request->filled('enseignant_id')) {
            $query->where('enseignant_id', $request->integer('enseignant_id'));
        }

        if ($request->filled('date_debut')) {
            $query->whereDate('date_disponible', '>=', $request->date('date_debut')->toDateString());
        }

        if ($request->filled('date_fin')) {
            $query->whereDate('date_disponible', '<=', $request->date('date_fin')->toDateString());
        }

        return response()->json($query->get());
    }

    /**
     * Création d'une disponibilité (administration ou enseignant
     * pour son propre compte via mes-disponibilites).
     */
    public function store(Request $request)
    {
        $donnees = $this->valider($request);

        $disponibilite = DisponibiliteJury::create($donnees);

        return response()->json([
            'message' => 'Disponibilité enregistrée.',
            'disponibilite' => $disponibilite->load('enseignant.user'),
        ], 201);
    }

    /**
     * Mise à jour d'une disponibilité.
     */
    public function update(Request $request, DisponibiliteJury $disponibilite)
    {
        $donnees = $this->valider($request, $disponibilite);

        $disponibilite->update($donnees);

        return response()->json([
            'message' => 'Disponibilité mise à jour.',
            'disponibilite' => $disponibilite->fresh('enseignant.user'),
        ]);
    }

    /**
     * Suppression d'une disponibilité.
     */
    public function destroy(DisponibiliteJury $disponibilite)
    {
        $disponibilite->delete();

        return response()->json(['message' => 'Disponibilité supprimée.']);
    }

    /**
     * Disponibilités de l'enseignant connecté.
     */
    public function mesDisponibilites(Request $request)
    {
        $enseignant = $request->user()->enseignant;

        if (!$enseignant) {
            return response()->json(['message' => 'Profil enseignant introuvable.'], 403);
        }

        return response()->json(
            DisponibiliteJury::where('enseignant_id', $enseignant->id)
                ->orderBy('date_disponible')
                ->orderBy('heure_debut')
                ->get()
        );
    }

    /**
     * Validation partagée entre store et update.
     */
    private function valider(Request $request, ?DisponibiliteJury $disponibilite = null): array
    {
        $regles = [
            'date_disponible' => [$disponibilite ? 'sometimes' : 'required', 'date'],
            'heure_debut' => [$disponibilite ? 'sometimes' : 'required', 'date_format:H:i:s,H:i'],
            'heure_fin' => [
                $disponibilite ? 'sometimes' : 'required',
                'date_format:H:i:s,H:i',
                function (string $attribut, mixed $valeur, \Closure $echec) use ($request) {
                    $debut = $request->input('heure_debut') ?? $request->route('disponibilite')?->heure_debut;

                    if ($debut !== null && $valeur !== null && substr((string) $valeur, 0, 8) <= substr((string) $debut, 0, 8)) {
                        $echec('L\'heure de fin doit être postérieure à l\'heure de début.');
                    }
                },
            ],
        ];

        if (!$disponibilite) {
            $regles['enseignant_id'] = ['required', 'integer', 'exists:enseignants,id'];
        }

        return $request->validate($regles);
    }
}
