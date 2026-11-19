<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Creneau;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreneauController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'date' => ['sometimes', 'date'],
            'statut' => [
                'sometimes',
                Rule::in(['disponible', 'planifie', 'indisponible', 'annule']),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Creneau::query()
            ->withCount('soutenances')
            ->orderBy('date_soutenance')
            ->orderBy('heure_debut');

        if ($request->filled('date')) {
            $query->whereDate('date_soutenance', $request->input('date'));
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->input('statut'));
        }

        return response()->json(
            $query->paginate((int) $request->input('per_page', 50))
        );
    }

    public function show(Creneau $creneau)
    {
        return response()->json(
            $creneau->loadCount('soutenances')
        );
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $this->validateIntervalle($data);

        $data['statut'] = $data['statut'] ?? 'disponible';

        $creneau = Creneau::create($data);

        return response()->json([
            'message' => 'Créneau créé.',
            'creneau' => $creneau,
        ], 201);
    }

    public function update(Request $request, Creneau $creneau)
    {
        $data = $this->validateData($request, $creneau);

        $date = $data['date_soutenance'] ?? $creneau->date_soutenance->format('Y-m-d');
        $debut = $data['heure_debut'] ?? substr((string) $creneau->heure_debut, 0, 5);
        $fin = $data['heure_fin'] ?? substr((string) $creneau->heure_fin, 0, 5);

        if ($fin <= $debut) {
            return response()->json([
                'message' => 'L’heure de fin doit être postérieure à l’heure de début.',
            ], 422);
        }

        $utilise = $creneau->soutenances()
            ->whereNotIn('statut', ['annulee', 'annulée'])
            ->exists();

        if ($utilise) {
            $horaireModifie =
                $date !== $creneau->date_soutenance->format('Y-m-d')
                || $debut !== substr((string) $creneau->heure_debut, 0, 5)
                || $fin !== substr((string) $creneau->heure_fin, 0, 5);

            if ($horaireModifie) {
                return response()->json([
                    'message' => 'Ce créneau est affecté à une soutenance. Annulez ou replanifiez celle-ci avant de modifier son horaire.',
                ], 409);
            }
        }

        $creneau->update($data);

        return response()->json([
            'message' => 'Créneau mis à jour.',
            'creneau' => $creneau->fresh(),
        ]);
    }

    public function destroy(Creneau $creneau)
    {
        if ($creneau->soutenances()->exists()) {
            return response()->json([
                'message' => 'Ce créneau est référencé par une soutenance et ne peut pas être supprimé.',
            ], 409);
        }

        $creneau->delete();

        return response()->json([
            'message' => 'Créneau supprimé.',
        ]);
    }

    private function validateData(Request $request, ?Creneau $creneau = null): array
    {
        $rules = [
            'date_soutenance' => ['sometimes', 'required', 'date'],
            'heure_debut' => ['sometimes', 'required', 'date_format:H:i'],
            'heure_fin' => ['sometimes', 'required', 'date_format:H:i'],
            'statut' => [
                'sometimes',
                'required',
                Rule::in(['disponible', 'planifie', 'indisponible', 'annule']),
            ],
        ];

        if (!$creneau) {
            $rules['date_soutenance'] = ['required', 'date'];
            $rules['heure_debut'] = ['required', 'date_format:H:i'];
            $rules['heure_fin'] = ['required', 'date_format:H:i'];
        }

        return $request->validate($rules);
    }

    private function validateIntervalle(array $data): void
    {
        if ($data['heure_fin'] <= $data['heure_debut']) {
            abort(response()->json([
                'message' => 'L’heure de fin doit être postérieure à l’heure de début.',
            ], 422));
        }
    }
}
