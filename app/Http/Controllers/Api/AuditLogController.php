<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Consultation des journaux de traçabilité (BF12).
 *
 * Conformité au livre :
 *   - RG17 : traçabilité des opérations importantes.
 *   - Acteur Administrateur : consulter les traces et journaux
 *     nécessaires à la sécurité et à la traçabilité.
 */
class AuditLogController extends Controller
{
    /**
     * Liste paginée des traces d'audit avec filtres.
     */
    public function index(Request $request)
    {
        $query = AuditLog::with('user')->orderByDesc('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('entite')) {
            $query->where('entite', $request->string('entite')->toString());
        }

        if ($request->filled('action')) {
            $query->whereLike('action', '%' . $request->string('action')->toString() . '%');
        }

        if ($request->filled('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date('date_debut')->toDateString());
        }

        if ($request->filled('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date('date_fin')->toDateString());
        }

        return response()->json(
            $query->paginate(min(max($request->integer('per_page', 30), 1), 200))
        );
    }

    /**
     * Nettoyage des journaux d'audit (bouton « Nettoyer »).
     *
     * Opération contrôlée, exécutée côté backend et réservée aux
     * administrateurs (contrôle par route + contrôle ici). Une
     * confirmation est exigée côté interface ; une trace de
     * l'opération est conservée après le nettoyage afin de maintenir
     * la traçabilité (RG17).
     */
    public function nettoyer(Request $request)
    {
        $utilisateur = $request->user()->loadMissing('role');

        if ($utilisateur->role->name !== 'administrateur') {
            return response()->json(['message' => 'Accès interdit : seuls les administrateurs peuvent nettoyer les journaux.'], 403);
        }

        $supprimes = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use (&$supprimes) {
            $supprimes = \App\Models\AuditLog::count();
            \App\Models\AuditLog::query()->delete();
        });

        // Une trace du nettoyage est réenregistrée : l'historique des
        // actions antérieures est vidé, mais l'opération elle-même
        // reste traçable.
        app(\App\Services\AuditService::class)->enregistrer(
            'NETTOYAGE_JOURNAUX',
            'audit_logs',
            null,
            "Nettoyage des journaux d'audit par l'administrateur — {$supprimes} entrée(s) supprimée(s)."
        );

        return response()->json([
            'message' => "Journaux nettoyés : {$supprimes} entrée(s) supprimée(s). Une trace de cette opération est conservée.",
            'supprimes' => $supprimes,
        ]);
    }
}
