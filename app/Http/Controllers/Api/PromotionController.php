<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\Formation;
use App\Models\Parcours;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $query = Promotion::with([
            'formation:id,code,nom',
            'parcours:id,formation_id,code,nom',
        ])->withCount('etudiants');

        if ($request->filled('parcours_id')) {
            $query->where('parcours_id', $request->integer('parcours_id'));
        }

        if ($request->filled('formation_id')) {
            $query->where('formation_id', $request->integer('formation_id'));
        }

        if ($request->filled('annee_universitaire')) {
            $query->where(
                'annee_universitaire',
                $request->string('annee_universitaire')->toString()
            );
        }

        return response()->json(
            $query->orderByDesc('annee_universitaire')
                ->orderBy('nom')
                ->paginate(min(max($request->integer('per_page', 100), 1), 200))
        );
    }

    public function show(int $id)
    {
        $promotion = Promotion::with([
            'formation',
            'parcours',
        ])->withCount('etudiants')->findOrFail($id);

        return response()->json(['data' => $promotion]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'parcours_id' => [
                'required',
                'integer',
                'exists:parcours,id',
            ],
            'nom' => ['required', 'string', 'max:100'],
            'annee_universitaire' => [
                'required',
                'string',
                'regex:/^\d{4}-\d{4}$/',
            ],
        ]);

        [$debut, $fin] = array_map(
            'intval',
            explode('-', $validated['annee_universitaire'])
        );

        if ($fin !== $debut + 1) {
            return response()->json([
                'message' => 'L’année universitaire doit être consécutive, par exemple 2026-2027.',
            ], 422);
        }

        $parcours = Parcours::with('formation')
            ->findOrFail($validated['parcours_id']);

        if (!$parcours->active || !$parcours->formation->active) {
            return response()->json([
                'message' => 'Le parcours ou sa mention est inactif.',
            ], 422);
        }

        $exists = Promotion::where('parcours_id', $validated['parcours_id'])
            ->where('nom', $validated['nom'])
            ->where('annee_universitaire', $validated['annee_universitaire'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Cette promotion existe déjà pour ce parcours et cette année universitaire.',
            ], 422);
        }

        $validated['formation_id'] = $parcours->formation_id;

        $promotion = Promotion::create($validated);

        return response()->json([
            'message' => 'Promotion créée avec succès.',
            'data' => $promotion->load(['formation', 'parcours']),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $promotion = Promotion::findOrFail($id);

        $validated = $request->validate([
            'parcours_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:parcours,id',
            ],
            'nom' => ['sometimes', 'required', 'string', 'max:100'],
            'annee_universitaire' => [
                'sometimes',
                'required',
                'string',
                'regex:/^\d{4}-\d{4}$/',
            ],
        ]);

        $annee = $validated['annee_universitaire']
            ?? $promotion->annee_universitaire;

        if (preg_match('/^\d{4}-\d{4}$/', $annee)) {
            [$debut, $fin] = array_map('intval', explode('-', $annee));

            if ($fin !== $debut + 1) {
                return response()->json([
                    'message' => 'L’année universitaire doit être consécutive, par exemple 2026-2027.',
                ], 422);
            }
        } else {
            return response()->json([
                'message' => 'L’année universitaire doit respecter le format YYYY-YYYY.',
            ], 422);
        }

        $parcoursId = $validated['parcours_id'] ?? $promotion->parcours_id;

        $parcours = Parcours::with('formation')->findOrFail($parcoursId);

        if (!$parcours->active || !$parcours->formation->active) {
            return response()->json([
                'message' => 'Le parcours ou sa mention est inactif.',
            ], 422);
        }

        $nom = $validated['nom'] ?? $promotion->nom;

        $exists = Promotion::where('parcours_id', $parcoursId)
            ->where('nom', $nom)
            ->where('annee_universitaire', $annee)
            ->where('id', '!=', $promotion->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Une promotion identique existe déjà.',
            ], 422);
        }

        $validated['parcours_id'] = $parcoursId;
        $validated['formation_id'] = $parcours->formation_id;

        $promotion->update($validated);

        return response()->json([
            'message' => 'Promotion modifiée avec succès.',
            'data' => $promotion->fresh()->load(['formation', 'parcours']),
        ]);
    }

    public function destroy(int $id)
    {
        $promotion = Promotion::findOrFail($id);

        if ($promotion->etudiants()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer une promotion contenant des étudiants.',
            ], 409);
        }

        $promotion->delete();

        return response()->json([
            'message' => 'Promotion supprimée avec succès.',
        ]);
    }
}
