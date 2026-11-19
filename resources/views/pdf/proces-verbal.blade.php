
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Procès-verbal de soutenance</title>

    <style>
        /* Mise en page compacte : le PV tient sur une page A4 recto. */
        @page {
            size: A4 portrait;
            margin: 12mm 14mm 10mm 14mm;
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 10pt;
            color: #000;
            /* Interligne aéré (demande utilisateur : le PV était trop
               serré entre les lignes). Le contenu reste sur une page. */
            line-height: 1.55;
        }

        .entete {
            width: 100%;
            border: 1px solid #000;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 10px;
        }

        .entete td {
            border: none;
            vertical-align: middle;
            padding: 4px 4px;
        }

        .logo-cell {
            width: 19%;
            text-align: center;
        }

        .texte-entete {
            width: 62%;
            text-align: center;
            font-size: 9pt;
            line-height: 1.2;
        }

        .logo {
            width: 58px;
            height: 58px;
            object-fit: contain;
        }

        .titre-principal {
            text-align: center;
            font-size: 13pt;
            font-weight: normal;
            text-decoration: underline;
            text-transform: uppercase;
            margin: 12px 0 10px;
        }

        .titre-diplome {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            line-height: 1.45;
            margin: 6px 0;
        }

        .informations {
            font-size: 10pt;
            line-height: 1.55;
            margin-top: 12px;
        }

        .ligne {
            margin: 7px 0;
        }

        .texte-soutenance {
            text-align: justify;
            margin-top: 12px;
            margin-bottom: 12px;
        }

        .champ {
            margin: 11px 0;
        }

        .souligne {
            text-decoration: underline;
        }

        .deliberation {
            text-align: justify;
            margin-top: 16px;
            margin-bottom: 16px;
        }

        .jury {
            margin-top: 14px;
            page-break-inside: avoid;
        }

        .jury p {
            margin: 13px 0;
        }

        .signature {
            margin-top: 26px;
            text-align: right;
            font-size: 10pt;
        }
    </style>
</head>

<body>
    @php
        $etudiant = $soutenance->etudiant;
        $promotion = $etudiant->promotion ?? null;

        $membres = $soutenance->jury->membresJury
            ->sortBy(function ($membre) {
                $fonction = strtolower(trim($membre->fonction));

                return match ($fonction) {
                    'president' => 1,
                    'rapporteur' => 2,
                    default => 3,
                };
            });

        $president = $membres->first(
            fn ($membre) =>
                strtolower(trim($membre->fonction)) === 'president'
        );

        $rapporteur = $membres->first(
            fn ($membre) =>
                strtolower(trim($membre->fonction)) === 'rapporteur'
        );

        $examinateurs = $membres->filter(
            fn ($membre) =>
                !in_array(
                    strtolower(trim($membre->fonction)),
                    ['president', 'rapporteur']
                )
        );

        $nomComplet = trim(
            ($etudiant->user->name ?? '')
        );

        // Date portée par la soutenance (nouveau) ou par son créneau
        // (anciennes données) — lecture sécurisée.
        $dateSoutenance = $soutenance->date_soutenance
            ?? $soutenance->creneau?->date_soutenance
            ?? null;

        $anneeUniversitaire = $promotion->annee_universitaire
            ?? $soutenance->session->annee_universitaire
            ?? '20... / 20...';

        $mention = $resultat->mention;

        $lieuStage = $etudiant->lieu_stage
            ?? $soutenance->lieu_stage
            ?? null;

        $dateNaissance = $etudiant->date_naissance ?? null;
        $lieuNaissance = $etudiant->lieu_naissance ?? null;

        // Le parcours affiché est celui de l'étudiant (relié via sa
        // promotion) ; la mention correspond à la formation suivie.
        $parcoursEtudiant = $promotion?->parcours ?? null;
        $formation = $promotion?->formation ?? $parcoursEtudiant?->formation ?? null;

        $parcours = $parcoursEtudiant?->nom;
        $mentionFormation = $formation?->nom;
    @endphp

    {{-- EN-TÊTE OFFICIEL ENCADRÉ --}}
    <table class="entete">
        <tr>
            <td class="logo-cell">
                <img
                    class="logo"
                    src="{{ public_path('images/logo-universite.png') }}"
                    alt="Université de Fianarantsoa"
                >
            </td>

            <td class="texte-entete">
                <div>UNIVERSITE DE FIANARANTSOA</div>
                <div>ÉCOLE DE MANAGEMENT ET D'INNOVATION TECHNOLOGIQUE</div>
                <div><strong>E.M.I.T.</strong></div>
                <div>TEL: 034.87.042.16 - 033.70.470.00 - BP: 1135</div>
                <div>e.mail: renseignement@emit.mg</div>
                <div>site web: www.emit.mg</div>
            </td>

            <td class="logo-cell">
                <img
                    class="logo"
                    src="{{ public_path('images/logo-emit.png') }}"
                    alt="EMIT"
                >
            </td>
        </tr>
    </table>

    {{-- TITRE --}}
    <h1 class="titre-principal">
        PROCES VERBAL DE SOUTENANCE
    </h1>

    <div class="titre-diplome">
        SOUTENANCE DE MEMOIRE EN VUE DE L'OBTENTION DU DIPLOME DE
        MASTER {{ strtoupper($typeMaster) }}
    </div>

    <div class="titre-diplome">
        MENTION :
        {{ $mentionFormation ?? '........................................' }}
    </div>

    <div class="titre-diplome">
        PARCOURS :
        {{ $parcours ?? '........................................' }}
    </div>

    <div class="titre-diplome">
        ANNEE UNIVERSITAIRE : {{ $anneeUniversitaire }}
    </div>

    {{-- INFORMATIONS DE L'IMPÉTRANT --}}
    <div class="informations">
        <div class="ligne">
            Nom de l'impétrant(e) :
            {{ $nomComplet ?: '........................................................' }}
        </div>

        <div class="ligne">
            Né(e) le :
            {{ $dateNaissance ? \Carbon\Carbon::parse($dateNaissance)->format('d/m/Y') : '........................' }}
            à
            {{ $lieuNaissance ?: '........................................' }}
        </div>
    </div>

    <div class="texte-soutenance">
        A soutenu publiquement son mémoire pour l'obtention du diplôme de
        Master {{ $typeMaster }} en
        {{ $mentionFormation ?? '................................' }},
        parcours {{ $parcours ?? '................................' }},
        à l'École de Management et d'Innovation Technologique (EMIT)
        de l'Université de Fianarantsoa.
    </div>

    <div class="champ">
        <span class="souligne">Intitulé du mémoire :</span>
        {{ $soutenance->titre_memoire ?? '........................................................' }}
    </div>

    <div class="champ">
        <span class="souligne">Lieu de stage :</span>
        {{ $lieuStage ?: '........................................................' }}
    </div>

    <div class="champ">
        <span class="souligne">Date de soutenance :</span>
        @if ($dateSoutenance)
            {{ \Carbon\Carbon::parse($dateSoutenance)->format('d/m/Y') }}
        @else
            ........................................
        @endif
    </div>

    <p class="texte-soutenance">
        Un exemplaire corrigé, plus un CD contenant la version numérique
        du mémoire devront être déposés à l'EMIT.
    </p>

    {{-- DÉLIBÉRATION --}}
    <div class="deliberation">
        Après délibération, le jury a déclaré l'admission du candidat
        et lui a décerné la mention
        <strong>{{ $mention ?? '................................' }}</strong>,
        avec une moyenne de note générale égale à :
        <strong>
            {{ number_format((float) $resultat->moyenne_finale, 2, ',', ' ') }}/20
        </strong>.
    </div>

    {{-- MEMBRES DU JURY --}}
    <div class="jury">
        <p class="souligne">Les membres du Jury :</p>

        <p>
            Le Président :
            {{ $president->enseignant->user->name ?? '........................................' }}
        </p>

        <p>
            Le Rapporteur :
            {{ $rapporteur->enseignant->user->name ?? '........................................' }}
        </p>

        <p>
            Le(s) Examinateur(s) :
            @forelse ($examinateurs as $examinateur)
                {{ $examinateur->enseignant->user->name ?? 'Nom non renseigné' }}@if (!$loop->last), @endif
            @empty
                ........................................
            @endforelse
        </p>
    </div>

    <div class="signature">
        Fait à Fianarantsoa, le {{ $dateGeneration->format('d/m/Y') }}
    </div>
</body>
</html>
