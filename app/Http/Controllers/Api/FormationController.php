<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Formation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FormationController extends Controller
{
    public function index(Request $request)
    {
        $formations = Formation::withCount('promotions')
            ->orderBy('nom')
            ->paginate(min(max($request->integer('per_page', 100), 1), 200));

        return response()->json($formations);
    }

    public function show(int $id)
    {
        $formation = Formation::with('promotions')
            ->withCount('promotions')
            ->findOrFail($id);

        return response()->json(['data' => $formation]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:formations,code'],
            'nom' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $formation = Formation::create($validated);

        return response()->json([
            'message' => 'Formation créée avec succès.',
            'data' => $formation,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $formation = Formation::findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'sometimes', 'required', 'string', 'max:30',
                Rule::unique('formations', 'code')->ignore($formation->id),
            ],
            'nom' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $formation->update($validated);

        return response()->json([
            'message' => 'Formation modifiée avec succès.',
            'data' => $formation->fresh(),
        ]);
    }

    public function destroy(int $id)
    {
        $formation = Formation::findOrFail($id);

        $formation->update(['active' => false]);

        return response()->json([
            'message' => 'Formation désactivée avec succès.',
            'data' => $formation->fresh(),
        ]);
    }
}
