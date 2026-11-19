<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salle;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalleController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'disponible_seulement' => ['sometimes', 'boolean'],
            'batiment' => ['sometimes', 'string', 'in:A,B,C,D,a,b,c,d'],
            'date' => ['sometimes', 'required_with:heure_debut,heure_fin', 'date'],
            'heure_debut' => ['sometimes', 'required_with:date,heure_fin', 'date_format:H:i'],
            'heure_fin' => ['sometimes', 'required_with:date,heure_debut', 'date_format:H:i', 'after:heure_debut'],
        ]);

        $query = Salle::query()->orderBy('code');

        if ($request->boolean('disponible_seulement')) {
            $query->where('disponible', true);
        }

        if ($request->filled('batiment')) {
            $query->where('batiment', strtoupper($request->input('batiment')));
        }

        if ($request->filled('date') && $request->filled('heure_debut') && $request->filled('heure_fin')) {
            $query->whereDoesntHave('soutenances', function ($soutenances) use ($request) {
                $soutenances
                    ->whereNotIn('statut', ['annulee', 'annulée'])
                    ->whereHas('creneau', function ($creneaux) use ($request) {
                        $creneaux
                            ->whereDate('date_soutenance', $request->input('date'))
                            ->where('heure_debut', '<', $request->input('heure_fin'))
                            ->where('heure_fin', '>', $request->input('heure_debut'));
                    });
            });
        }

        return response()->json($query->get());
    }

    public function disponibles(Request $request)
    {
        $request->merge(['disponible_seulement' => true]);

        return $this->index($request);
    }

    public function show(Salle $salle)
    {
        return response()->json($salle);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);

        $code = strtoupper($data['code']);
        $emplacement = $this->emplacementDepuisCode($code);

        $data['code'] = $code;
        $data['batiment'] = $emplacement['batiment'];
        $data['etage'] = $emplacement['etage'];

        $salle = Salle::create($data);

        return response()->json([
            'message' => 'Salle créée.',
            'salle' => $salle,
        ], 201);
    }

    public function update(Request $request, Salle $salle)
    {
        $data = $this->validatedData($request, $salle);

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
            $emplacement = $this->emplacementDepuisCode($data['code']);
            $data['batiment'] = $emplacement['batiment'];
            $data['etage'] = $emplacement['etage'];
        }

        $salle->update($data);

        return response()->json([
            'message' => 'Salle mise à jour.',
            'salle' => $salle->fresh(),
        ]);
    }

    public function destroy(Salle $salle)
    {
        if ($salle->soutenances()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer une salle associée à des soutenances. Désactivez-la plutôt.',
            ], 409);
        }

        $salle->delete();

        return response()->json(['message' => 'Salle supprimée.']);
    }

    private function validatedData(Request $request, ?Salle $salle = null): array
    {
        return $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:4',
                function ($attribute, $value, $fail) {
                    $code = strtoupper($value);

                    $valid = preg_match('/^B[0-4]0[1-3]$/', $code)
                        || preg_match('/^A0(0[1-9]|1[0-5])$/', $code)
                        || preg_match('/^A1(0[1-9]|1[0-5])$/', $code)
                        || preg_match('/^C00[1-3]$/', $code)
                        || preg_match('/^D(001|101)$/', $code);

                    if (!$valid) {
                        $fail('Le code de salle ne respecte pas les formats autorisés.');
                    }
                },
                Rule::unique('salles', 'code')->ignore($salle?->id),
            ],
            'nom' => ['sometimes', 'required', 'string', 'max:100'],
            'capacite' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
            'localisation' => ['nullable', 'string', 'max:150'],
            'disponible' => ['sometimes', 'boolean'],
        ]);
    }

    private function emplacementDepuisCode(string $code): array
    {
        $batiment = substr($code, 0, 1);

        $etage = match ($batiment) {
            'A' => (int) substr($code, 1, 1),
            'B' => (int) substr($code, 1, 1),
            'C' => null,
            'D' => $code === 'D001' ? 0 : 1,
            default => null,
        };

        return [
            'batiment' => $batiment,
            'etage' => $etage,
        ];
    }
}
