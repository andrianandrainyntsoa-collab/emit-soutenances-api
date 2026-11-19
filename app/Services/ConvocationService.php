<?php

namespace App\Services;

use App\Models\Convocation;
use App\Models\MembreJury;
use App\Models\Soutenance;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Service de gestion des convocations.
 *
 * Conformité au livre :
 *   - BF06 : générer et mettre à disposition les convocations.
 *   - Les convocations ne sont générées qu'à partir d'un planning
 *     établi (soutenance planifiée avec créneau, salle et jury).
 *   - RG14 : l'étudiant consulte et télécharge sa convocation ;
 *     les membres du jury accèdent à la leur.
 */
class ConvocationService
{
    public const TYPE_ETUDIANT = 'etudiant';
    public const TYPE_MEMBRE_JURY = 'membre_jury';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    /**
     * Génère les convocations d'une soutenance : une pour
     * l'étudiant et une pour chaque membre du jury.
     *
     * @return Convocation[] Les convocations générées (les existantes sont retournées telles quelles).
     */
    public function genererPourSoutenance(Soutenance $soutenance): array
    {
        $this->verifierSoutenanceConvocable($soutenance);

        $soutenance->load([
            'etudiant.user', 'etudiant.promotion.formation', 'etudiant.promotion.parcours',
            'session', 'salle', 'creneau', 'jury.membresJury.enseignant.user',
        ]);

        $genernees = [];

        // Convocation de l'étudiant.
        $genernees[] = $this->genererUne(
            $soutenance,
            self::TYPE_ETUDIANT,
            $soutenance->etudiant->user,
            null
        );

        // Convocations des membres du jury.
        foreach ($soutenance->jury->membresJury as $membre) {
            $genernees[] = $this->genererUne(
                $soutenance,
                self::TYPE_MEMBRE_JURY,
                $membre->enseignant->user,
                $membre
            );
        }

        $this->audit->enregistrer(
            'GENERATION_CONVOCATIONS',
            'soutenances',
            $soutenance->id,
            'Génération des convocations pour la soutenance #' . $soutenance->id . '.'
        );

        return $genernees;
    }

    /**
     * Génère (ou regénère) une convocation individuelle.
     */
    private function genererUne(
        Soutenance $soutenance,
        string $typeDestinataire,
        ?object $destinataireUser,
        ?MembreJury $membreJury
    ): Convocation {
        $existante = Convocation::where('soutenance_id', $soutenance->id)
            ->where('type_destinataire', $typeDestinataire)
            ->when(
                $typeDestinataire === self::TYPE_MEMBRE_JURY,
                fn ($query) => $query->where('destinataire_user_id', $destinataireUser?->id)
            )
            ->first();

        if ($existante) {
            return $existante;
        }

        $numeroConvocation = sprintf(
            'CONV-%s-%s-%06d',
            strtoupper(substr($typeDestinataire, 0, 2)),
            now()->format('Y'),
            $soutenance->id
        ) . ($typeDestinataire === self::TYPE_MEMBRE_JURY ? '-' . $destinataireUser?->id : '');

        $pdf = Pdf::loadView('pdf.convocation', [
            'soutenance' => $soutenance,
            'typeDestinataire' => $typeDestinataire,
            'destinataire' => $destinataireUser,
            'membreJury' => $membreJury,
            'numeroConvocation' => $numeroConvocation,
            'dateGeneration' => now(),
        ])->setPaper('a4', 'portrait');

        $chemin = 'convocations/' . $numeroConvocation . '.pdf';

        Storage::disk(config('filesystems.documents'))->put($chemin, $pdf->output());

        return Convocation::create([
            'soutenance_id' => $soutenance->id,
            'type_destinataire' => $typeDestinataire,
            'destinataire_user_id' => $destinataireUser?->id,
            'date_generation' => now(),
            'date_envoi' => null,
            'statut' => 'generee',
            'fichier_path' => $chemin,
        ]);
    }

    /**
     * CORRECTION (« document introuvable sur le serveur ») : régénère
     * le fichier PDF d'une convocation existante lorsque le fichier a
     * disparu du stockage du serveur (p.ex. après une recopie ou une
     * réinstallation du projet : les enregistrements de la base
     * subsistent mais le dossier storage a été vidé).
     *
     * Le document est reconstruit À L'IDENTIQUE à partir des données
     * officielles enregistrées en base (même vue, même numéro de
     * convocation dérivé du chemin d'origine, même emplacement).
     * Aucune donnée n'est modifiée : seul le fichier est recréé.
     *
     * Retourne true si le fichier est disponible (déjà présent ou
     * régénéré), false si la régénération est impossible.
     */
    public function regenererFichier(Convocation $convocation): bool
    {
        if (
            $convocation->fichier_path
            && Storage::disk(config('filesystems.documents'))->exists($convocation->fichier_path)
        ) {
            return true;
        }

        $soutenance = $convocation->soutenance()->with([
            'etudiant.user', 'etudiant.promotion.formation', 'etudiant.promotion.parcours',
            'session', 'salle', 'creneau', 'jury.membresJury.enseignant.user',
        ])->first();

        if (!$soutenance) {
            return false;
        }

        $membreJury = null;
        $destinataireUser = null;

        if ($convocation->type_destinataire === self::TYPE_ETUDIANT) {
            $destinataireUser = $soutenance->etudiant?->user;
        } else {
            $membreJury = $soutenance->jury?->membresJury->first(
                fn ($membre) =>
                    (int) $membre->enseignant?->user_id === (int) $convocation->destinataire_user_id
            );
            $destinataireUser = $membreJury?->enseignant?->user;
        }

        if (!$destinataireUser) {
            return false;
        }

        // Numéro de convocation : dérivé du chemin d'origine si connu
        // (le numéro est encodé dans le nom du fichier), sinon recalculé
        // selon la formule de génération.
        $numeroConvocation = $convocation->fichier_path
            ? basename($convocation->fichier_path, '.pdf')
            : sprintf(
                'CONV-%s-%s-%06d',
                strtoupper(substr($convocation->type_destinataire, 0, 2)),
                now()->format('Y'),
                $soutenance->id
            ) . ($convocation->type_destinataire === self::TYPE_MEMBRE_JURY
                ? '-' . $convocation->destinataire_user_id
                : '');

        $chemin = $convocation->fichier_path
            ?: 'convocations/' . $numeroConvocation . '.pdf';

        try {
            $pdf = Pdf::loadView('pdf.convocation', [
                'soutenance' => $soutenance,
                'typeDestinataire' => $convocation->type_destinataire,
                'destinataire' => $destinataireUser,
                'membreJury' => $membreJury,
                'numeroConvocation' => $numeroConvocation,
                'dateGeneration' => $convocation->date_generation ?? now(),
            ])->setPaper('a4', 'portrait');

            Storage::disk(config('filesystems.documents'))->put($chemin, $pdf->output());
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        if ($convocation->fichier_path !== $chemin) {
            $convocation->fichier_path = $chemin;
            $convocation->save();
        }

        return true;
    }

    /**
     * Diffuse (envoie) une convocation : statut passe à « envoyee »
     * et le destinataire reçoit une notification dans son espace.
     */
    public function diffuser(Convocation $convocation): Convocation
    {
        if ($convocation->statut === 'a_generer') {
            throw ValidationException::withMessages([
                'statut' => 'Cette convocation n\'est pas encore générée.',
            ]);
        }

        if ($convocation->date_envoi === null) {
            $convocation->statut = 'envoyee';
            $convocation->date_envoi = now();
            $convocation->save();

            if ($convocation->destinataire_user_id) {
                $soutenance = $convocation->soutenance;

                $this->notifications->envoyer(
                    $convocation->destinataire_user_id,
                    'Convocation disponible',
                    sprintf(
                        'Votre convocation à la soutenance de Master "%s" est disponible. Date : %s à %s — Salle : %s.',
                        $soutenance->titre_memoire,
                        $soutenance->creneau?->date_soutenance?->format('d/m/Y') ?? 'à confirmer',
                        $soutenance->creneau ? substr((string) $soutenance->creneau->heure_debut, 0, 5) : 'à confirmer',
                        $soutenance->salle?->code ?? 'à confirmer'
                    ),
                    'convocation',
                    ['soutenance_id' => $soutenance->id, 'convocation_id' => $convocation->id]
                );
            }

            $this->audit->enregistrer(
                'DIFFUSION_CONVOCATION',
                'convocations',
                $convocation->id,
                'Diffusion de la convocation ' . $convocation->id . '.'
            );
        }

        return $convocation->fresh();
    }

    /**
     * Envoi groupé : génère (si nécessaire) et envoie par e-mail une
     * convocation INDIVIDUELLE à chaque destinataire d'une soutenance
     * (l'étudiant + chaque membre du jury), à l'adresse réellement
     * enregistrée sur son compte.
     *
     * Chaque destinataire reçoit sa propre convocation avec son PDF
     * en pièce jointe — il ne s'agit pas d'un courriel collectif.
     *
     * @return array{envoyees: Convocation[], echecs: array, total: int}
     */
    public function envoyerPourSoutenance(Soutenance $soutenance): array
    {
        // Génère les convocations manquantes avant l'envoi.
        $convocations = collect($this->genererPourSoutenance($soutenance));

        $convocations->each->loadMissing('soutenance.creneau');

        $envoyees = [];
        $echecs = [];

        foreach ($convocations as $convocation) {
            $destinataire = $convocation->destinataire;

            if (!$destinataire || !$destinataire->email) {
                $echecs[] = [
                    'convocation_id' => $convocation->id,
                    'destinataire' => $destinataire?->name ?? 'Destinataire inconnu',
                    'email' => null,
                    'erreur' => 'Aucune adresse e-mail enregistrée sur ce compte.',
                ];
                continue;
            }

            if (!Storage::disk(config('filesystems.documents'))->exists($convocation->fichier_path)) {
                $echecs[] = [
                    'convocation_id' => $convocation->id,
                    'destinataire' => $destinataire->name,
                    'email' => $destinataire->email,
                    'erreur' => 'Le fichier PDF de la convocation est introuvable.',
                ];
                continue;
            }

            $pdfBytes = Storage::disk(config('filesystems.documents'))->get($convocation->fichier_path);

            try {
                Mail::to($destinataire->email)->send(new \App\Mail\ConvocationSoutenance(
                    nomDestinataire: $destinataire->name,
                    titreMemoire: $convocation->soutenance->titre_memoire,
                    nomEtudiant: $convocation->soutenance->etudiant?->user?->name ?? '—',
                    date: $convocation->soutenance->date_soutenance?->format('d/m/Y')
                        ?? $convocation->soutenance->creneau?->date_soutenance?->format('d/m/Y') ?? 'à confirmer',
                    heure: $convocation->soutenance->heure_debut
                        ? substr((string) $convocation->soutenance->heure_debut, 0, 5)
                        : (substr((string) $convocation->soutenance->creneau?->heure_debut, 0, 5) ?: 'à confirmer'),
                    salle: $convocation->soutenance->salle?->code ?? 'à confirmer',
                    pdfBytes: $pdfBytes,
                ));

                if ($convocation->statut !== 'consultee') {
                    $convocation->statut = 'envoyee';
                }
                $convocation->date_envoi = now();
                $convocation->save();

                // Notification dans l'espace du destinataire.
                $this->notifications->envoyer(
                    $convocation->destinataire_user_id,
                    'Convocation envoyée par e-mail',
                    sprintf(
                        'Votre convocation à la soutenance de Master « %s » vous a été envoyée par e-mail. '
                        .'Date : %s à %s — Salle : %s.',
                        $convocation->soutenance->titre_memoire,
                        $convocation->soutenance->date_soutenance?->format('d/m/Y')
                            ?? $convocation->soutenance->creneau?->date_soutenance?->format('d/m/Y') ?? 'à confirmer',
                        $convocation->soutenance->heure_debut
                            ? substr((string) $convocation->soutenance->heure_debut, 0, 5)
                            : (substr((string) $convocation->soutenance->creneau?->heure_debut, 0, 5) ?: 'à confirmer'),
                        $convocation->soutenance->salle?->code ?? 'à confirmer'
                    ),
                    'convocation',
                    ['soutenance_id' => $convocation->soutenance_id, 'convocation_id' => $convocation->id]
                );

                $envoyees[] = $convocation->fresh();

                $this->audit->enregistrer(
                    'ENVOI_EMAIL_CONVOCATION',
                    'convocations',
                    $convocation->id,
                    'Convocation envoyée par e-mail à ' . $destinataire->email . '.'
                );
            } catch (\Throwable $e) {
                report($e);

                $echecs[] = [
                    'convocation_id' => $convocation->id,
                    'destinataire' => $destinataire->name,
                    'email' => $destinataire->email,
                    'erreur' => 'Échec de l\'envoi SMTP : ' . $this->messageErreur($e),
                ];
            }
        }

        if ($echecs === []) {
            $this->audit->enregistrer(
                'DIFFUSION_CONVOCATIONS_SOUTENANCE',
                'soutenances',
                $soutenance->id,
                'Toutes les convocations de la soutenance #' . $soutenance->id . ' ont été envoyées par e-mail.'
            );
        }

        return [
            'envoyees' => $envoyees,
            'echecs' => $echecs,
            'total' => $convocations->count(),
        ];
    }

    /**
     * Liste des soutenances groupées pour la page des convocations :
     * une ligne = une soutenance (destinataires regroupés).
     */
    public function listingParSoutenance()
    {
        $soutenances = Soutenance::with([
            'etudiant.user', 'session', 'salle', 'creneau',
            'jury.membresJury.enseignant.user',
            'convocations.destinataire',
        ])
            ->whereHas('convocations')
            ->orderByDesc('created_at')
            ->get();

        return $soutenances->map(function (Soutenance $soutenance) {
            $convocations = $soutenance->convocations;

            $attendus = collect();

            if ($soutenance->etudiant?->user) {
                $attendus->push($soutenance->etudiant->user);
            }

            foreach ($soutenance->jury?->membresJury ?? [] as $membre) {
                if ($membre->enseignant?->user) {
                    $attendus->push($membre->enseignant->user);
                }
            }

            $sansEmail = $attendus->filter(fn ($u) => !$u->email)->values();

            $toutesEnvoyees = $convocations->count() > 0
                && $convocations->every(fn ($c) => $c->date_envoi !== null);

            return [
                'soutenance' => $soutenance,
                'destinataires' => $attendus->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                ])->values(),
                'total_convocations' => $convocations->count(),
                'convocations_envoyees' => $convocations->where('date_envoi', '!=', null)->count(),
                'toutes_envoyees' => $toutesEnvoyees,
                'emails_manquants' => $sansEmail->count(),
                'envoyable' => !$toutesEnvoyees
                    && $soutenance->jury_id !== null
                    && $soutenance->salle_id !== null
                    && !in_array($soutenance->statut, ['annulee', 'annulée'], true),
            ];
        })->values();
    }

    /**
     * Traduit une exception d'envoi en message explicite sans
     * exposer d'informations confidentielles.
     */
    private function messageErreur(\Throwable $e): string
    {
        return match (true) {
            str_contains($e->getMessage(), 'authenticate') || str_contains($e->getMessage(), 'Expected response code 250')
                => 'authentification SMTP échouée — vérifiez MAIL_USERNAME / MAIL_PASSWORD (mot de passe d\'application Google) dans le fichier .env.',
            str_contains($e->getMessage(), 'connection')
                => 'connexion au serveur SMTP échouée — vérifiez MAIL_HOST et MAIL_PORT.',
            default => mb_substr($e->getMessage(), 0, 180),
        };
    }

    /**
     * Une soutenance est convocabable lorsque son planning est
     * entièrement établi (créneau + salle + jury complet).
     */
    private function verifierSoutenanceConvocable(Soutenance $soutenance): void
    {
        // Le planning doit être établi : soit via les horaires portés
        // par la soutenance (nouveau flux), soit via son créneau lié
        // (anciennes données).
        if ((!$soutenance->date_soutenance && !$soutenance->creneau_id) || !$soutenance->salle_id) {
            throw ValidationException::withMessages([
                'soutenance_id' => 'La soutenance n\'est pas encore planifiée (date, heures et salle requis).',
            ]);
        }

        if (!$soutenance->jury_id) {
            throw ValidationException::withMessages([
                'soutenance_id' => 'Aucun jury n\'est affecté à cette soutenance.',
            ]);
        }

        if (in_array($soutenance->statut, ['annulee', 'annulée'], true)) {
            throw ValidationException::withMessages([
                'soutenance_id' => 'Cette soutenance est annulée.',
            ]);
        }
    }
}
