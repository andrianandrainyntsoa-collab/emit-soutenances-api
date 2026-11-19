<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Réinitialisation du mot de passe par code envoyé par e-mail.
 *
 * Flux :
 *   1. L'utilisateur saisit son adresse e-mail.
 *   2. Un code à 6 chiffres lui est envoyé par e-mail (valable 30 min).
 *   3. Il saisit le code + son nouveau mot de passe.
 *
 * Si l'envoi d'e-mail n'est pas encore configuré (MAIL_MAILER=log en
 * local), le code est écrit dans les logs et renvoyé en mode
 * développement afin de pouvoir tester la fonctionnalité.
 */
class MotDePasseController extends Controller
{
    /** Durée de validité d'un code (minutes) — configurable via .env. */
    private function dureeValidite(): int
    {
        return max((int) config('soutenance.reset_code_ttl', 30), 5);
    }

    /** Nombre maximal de demandes de codes par adresse et par heure. */
    private const MAX_DEMANDES_PAR_HEURE = 3;

    /** Nombre maximal de tentatives de saisie d'un code. */
    private const MAX_TENTATIVES = 5;

    /**
     * Étape 1 : demande d'un code de réinitialisation.
     */
    public function oublier(Request $request)
    {
        $donnees = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = mb_strtolower(trim($donnees['email']));

        // Protection contre les abus : au maximum 3 demandes de code
        // par adresse et par heure (compteur indépendant des codes
        // enregistrés, qui sont supprimés à chaque nouvelle demande).
        $cleDemandes = 'reset_code_demandes:' . sha1($email);
        $demandesRecentes = (int) Cache::get($cleDemandes, 0);

        if ($demandesRecentes >= self::MAX_DEMANDES_PAR_HEURE) {
            return response()->json([
                'message' => 'Trop de demandes de code pour cette adresse. Réessayez dans une heure.',
            ], 429);
        }

        Cache::put($cleDemandes, $demandesRecentes + 1, now()->addHour());

        $user = User::where('email', $email)->first();

        // Réponse volontairement identique que le compte existe ou non
        // (on ne révèle pas les adresses enregistrées).
        $reponse = [
            'message' => 'Un code de réinitialisation à 6 chiffres vous a été envoyé par e-mail. '
                ."Il est valable {$this->dureeValidite()} minutes.",
        ];

        if (!$user) {
            return response()->json($reponse);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_codes')->where('email', $email)->delete();

        PasswordResetCode::create([
            'email' => $email,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes($this->dureeValidite()),
        ]);

        $envoye = true;

        try {
            Mail::to($email)->send(new \App\Mail\CodeReinitialisation(
                nomUtilisateur: $user->name,
                code: $code,
                dureeMinutes: $this->dureeValidite(),
            ));
        } catch (\Throwable $e) {
            report($e);
            $envoye = false;
        }

        $transportDeveloppement = in_array(config('mail.default'), ['log', 'array'], true);

        if (!$envoye) {
            // L'utilisateur reçoit un message explicite en cas d'échec
            // d'envoi SMTP (pas de silence, pas de code de repli).
            return response()->json([
                'message' => 'Échec de l\'envoi de l\'e-mail. Vérifiez la configuration SMTP du serveur '
                    .'(MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD — mot de passe '
                    .'d\'application Google) puis réessayez.',
            ], 503);
        }

        // Aide au développement / démonstration : avec le pilote « log »
        // ou « array » (aucun SMTP réel), le code est renvoyé ici et
        // écrit dans les logs afin de pouvoir tester le flux. Avec un
        // SMTP réel (ex. Gmail), ce champ n'est JAMAIS renvoyé.
        if ($transportDeveloppement && app()->environment() !== 'production') {
            $reponse['code_developpement'] = $code;
        }

        return response()->json($reponse);
    }

    /**
     * Étape 2 : vérification du code + nouveau mot de passe.
     */
    public function reinitialiser(Request $request)
    {
        $donnees = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:10'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        $email = mb_strtolower(trim($donnees['email']));

        // Protection contre le brute-force du code : au maximum
        // MAX_TENTATIVES saisies erronées, puis le code est invalidé.
        $cleTentatives = 'reset_code_tentatives:' . sha1($email);
        $tentatives = (int) Cache::get($cleTentatives, 0);

        if ($tentatives >= self::MAX_TENTATIVES) {
            DB::table('password_reset_codes')->where('email', $email)->delete();
            Cache::forget($cleTentatives);

            return response()->json([
                'message' => 'Trop de tentatives incorrectes. Le code a été invalidé : demandez un nouveau code.',
            ], 429);
        }

        $enregistrement = PasswordResetCode::where('email', $email)
            ->orderByDesc('created_at')
            ->first();

        if (!$enregistrement
            || $enregistrement->used_at !== null
            || $enregistrement->expires_at->isPast()
            || !Hash::check(trim($donnees['code']), $enregistrement->code)
        ) {
            Cache::put($cleTentatives, $tentatives + 1, now()->addMinutes($this->dureeValidite()));

            $restantes = self::MAX_TENTATIVES - ($tentatives + 1);

            return response()->json([
                'message' => 'Code invalide ou expiré. Demandez un nouveau code.'
                    .($restantes > 0 ? " Tentatives restantes : {$restantes}." : ''),
            ], 422);
        }

        Cache::forget($cleTentatives);

        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Aucun compte associé à cette adresse e-mail.',
            ], 404);
        }

        DB::transaction(function () use ($user, $enregistrement, $donnees) {
            $user->password = Hash::make($donnees['password']);
            $user->save();

            $enregistrement->used_at = now();
            $enregistrement->save();

            // Sécurité : révoque les jetons d'accès existants.
            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès. Vous pouvez vous connecter '
                .'avec votre nouveau mot de passe.',
        ]);
    }
}
