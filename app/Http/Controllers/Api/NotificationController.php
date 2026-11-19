<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * Notifications de l'utilisateur connecté (espace personnel).
 *
 * Conformité au livre :
 *   - Espace personnel : consultation de son profil et de ses
 *     notifications.
 *   - Dictionnaire de données : statut NON_LUE / LUE.
 */
class NotificationController extends Controller
{
    /**
     * Notifications de l'utilisateur courant.
     */
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'non_lues' => $notifications->where('lue_le', null)->count(),
        ]);
    }

    /**
     * Nombre de notifications non lues (badge).
     */
    public function nonLuesCount(Request $request)
    {
        return response()->json([
            'non_lues' => Notification::where('user_id', $request->user()->id)
                ->whereNull('lue_le')
                ->count(),
        ]);
    }

    /**
     * Marque une notification comme lue.
     */
    public function marquerLue(Request $request, Notification $notification)
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Accès interdit à cette notification.'], 403);
        }

        $notification->marquerCommeLue();

        return response()->json([
            'message' => 'Notification marquée comme lue.',
            'notification' => $notification->fresh(),
        ]);
    }

    /**
     * Marque toutes les notifications de l'utilisateur comme lues.
     */
    public function marquerToutesLues(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->whereNull('lue_le')
            ->update(['lue_le' => now()]);

        return response()->json(['message' => 'Toutes les notifications sont marquées comme lues.']);
    }

    /**
     * Supprime UNE notification de l'utilisateur connecté.
     *
     * Utilisée par les boutons « Supprimer » de la page Notifications
     * (application web) et de l'écran Notifications (application
     * mobile). Une notification ne peut être supprimée que par son
     * propriétaire.
     */
    public function supprimer(Request $request, Notification $notification)
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Accès interdit à cette notification.'], 403);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification supprimée.']);
    }

    /**
     * Supprime TOUTES les notifications de l'utilisateur connecté
     * (bouton « Tout supprimer »).
     *
     * Paramètre optionnel : lues_seulement=1 pour ne supprimer que
     * les notifications déjà lues (les non lues sont conservées pour
     * ne rien manquer).
     */
    public function supprimerToutes(Request $request)
    {
        $supprimees = Notification::where('user_id', $request->user()->id)
            ->when(
                $request->boolean('lues_seulement'),
                fn ($query) => $query->whereNotNull('lue_le')
            )
            ->delete();

        return response()->json([
            'message' => $supprimees === 0
                ? 'Aucune notification à supprimer.'
                : ($request->boolean('lues_seulement')
                    ? "{$supprimees} notification(s) lue(s) supprimée(s)."
                    : "{$supprimees} notification(s) supprimée(s)."),
            'supprimees' => $supprimees,
        ]);
    }
}
