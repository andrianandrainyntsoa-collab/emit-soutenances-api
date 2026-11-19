<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisponibiliteSalle;
use Illuminate\Http\Request;

/**
 * Gestion des disponibilités des salles (BF04).
 *
 * Conformité au livre :
 *   - BF04 : gérer les salles et leurs disponibilités.
 *   - PlanificationService : si des disponibilités sont déclarées
 *     pour une salle, le créneau planifié doit être entièrement
 *     couvert par l'une d'elles.
 */
class DisponibiliteSalleController extends Controller
{
    /**
     * Liste des disponibilités de salles.
     */
    public function index(Request $request)
    {
        $query = DisponibiliteSalle::with('salle')
            ->orderBy('date_disponible')
            ->orderBy('heure_debut');

        if ($request->filled('salle_id')) {
            $query->where('salle_id', $request->integer('salle_id'));
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
     * Création d'une disponibilité de salle.
     */
    public function store(Request $request)
    {
        $donnees = $this->valider($request);

        $disponibilite = DisponibiliteSalle::create($donnees);

        return response()->json([
            'message' => 'Disponibilité de salle enregistrée.',
            'disponibilite' => $disponibilite->load('salle'),
        ], 201);
    }

    /**
     * Mise à jour d'une disponibilité de salle.
     */
    public function update(Request $request, DisponibiliteSalle $disponibilite)
    {
        $donnees = $this->valider($request, $disponibilite);

        $disponibilite->update($donnees);

        return response()->json([
            'message' => 'Disponibilité de salle mise à jour.',
            'disponibilite' => $disponibilite->fresh('salle'),
        ]);
    }

    /**
     * Suppression d'une disponibilité de salle.
     */
    public function destroy(DisponibiliteSalle $disponibilite)
    {
        $disponibilite->delete();

        return response()->json(['message' => 'Disponibilité de salle supprimée.']);
    }

    /**
     * Validation partagée.
     */
    private function valider(Request $request, ?DisponibiliteSalle $disponibilite = null): array
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
            $regles['salle_id'] = ['required', 'integer', 'exists:salles,id'];
        }

        return $request->validate($regles);
    }
}
