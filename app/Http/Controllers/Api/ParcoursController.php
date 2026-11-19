<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formation;
use App\Models\Parcours;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ParcoursController extends Controller
{
    public function index(Request $request)
    {
        $query = Parcours::with('formation:id,code,nom')
            ->withCount('promotions');

        if ($request->filled('formation_id')) {
            $query->where('formation_id', $request->integer('formation_id'));
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return response()->json(
            $query->orderBy('nom')->paginate(min(max($request->integer('per_page', 100), 1), 200))
        );
    }

    public function show(int $id)
    {
        $parcours = Parcours::with('formation')
            ->withCount('promotions')
            ->findOrFail($id);

        return response()->json(['data' => $parcours]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'formation_id' => [
                'required',
                'integer',
                'exists:formations,id',
            ],
            'code' => [
                'required',
                'string',
                'max:30',
                'unique:parcours,code',
            ],
            'nom' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $formation = Formation::findOrFail($validated['formation_id']);

        if (!$formation->active) {
            return response()->json([
                'message' => 'Impossible de créer un parcours dans une mention inactive.',
            ], 422);
        }

        $parcours = Parcours::create($validated);

        return response()->json([
            'message' => 'Parcours créé avec succès.',
            'data' => $parcours->load('formation'),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $parcours = Parcours::findOrFail($id);

        $validated = $request->validate([
            'formation_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:formations,id',
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('parcours', 'code')->ignore($parcours->id),
            ],
            'nom' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $formationId = $validated['formation_id'] ?? $parcours->formation_id;
        $formation = Formation::findOrFail($formationId);

        if (!$formation->active) {
            return response()->json([
                'message' => 'Impossible d’associer un parcours à une mention inactive.',
            ], 422);
        }

        $nom = $validated['nom'] ?? $parcours->nom;

        $duplicate = Parcours::where('formation_id', $formationId)
            ->where('nom', $nom)
            ->where('id', '!=', $parcours->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Ce parcours existe déjà dans cette mention.',
            ], 422);
        }

        $parcours->update($validated);

        return response()->json([
            'message' => 'Parcours modifié avec succès.',
            'data' => $parcours->fresh()->load('formation'),
        ]);
    }

    public function destroy(int $id)
    {
        $parcours = Parcours::findOrFail($id);

        if ($parcours->promotions()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer un parcours associé à des promotions.',
            ], 409);
        }

        $parcours->delete();

        return response()->json([
            'message' => 'Parcours supprimé avec succès.',
        ]);
    }
}
