<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Convocation à une soutenance de Master</title>

    <style>
        @page {
            size: A4 portrait;
            margin: 11mm 13mm 9mm 13mm;
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 10.5pt;
            color: #000;
            line-height: 1.3;
        }

        /* Mise en page compacte : l'ensemble de la convocation — y
           compris la signature « La Direction de l'E.M.I.T. » et la
           note de bas de page — tient sur UNE SEULE page A4, même avec
           des noms / intitulés longs (demande utilisateur). */
        .entete {
            width: 100%;
            border: 1px solid #000;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 7px;
        }

        .entete td {
            border: none;
            vertical-align: middle;
            padding: 3px 4px;
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
            width: 52px;
            height: 52px;
            object-fit: contain;
        }

        .reference {
            text-align: right;
            font-size: 8.5pt;
            margin-bottom: 2px;
        }

        .titre-principal {
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            text-transform: uppercase;
            text-decoration: underline;
            margin: 7px 0 7px;
        }

        .destinataire {
            margin: 6px 0;
            line-height: 1.3;
            font-size: 10.5pt;
        }

        .corps {
            text-align: justify;
            margin: 7px 0;
            line-height: 1.35;
        }

        .table-infos {
            width: 100%;
            border-collapse: collapse;
            margin: 7px 0;
            page-break-inside: avoid;
        }

        .table-infos td {
            border: 1px solid #000;
            padding: 3.5px 7px;
            font-size: 9.5pt;
            line-height: 1.3;
        }

        .table-infos .libelle {
            width: 36%;
            font-weight: bold;
        }

        .jury {
            margin-top: 6px;
            page-break-inside: avoid;
        }

        .jury p {
            margin: 2.5px 0;
        }

        .signature {
            margin-top: 14px;
            text-align: right;
            line-height: 1.3;
            page-break-inside: avoid;
        }

        .note-bas {
            margin-top: 10px;
            font-size: 8.5pt;
            text-align: justify;
            font-style: italic;
            line-height: 1.3;
        }
    </style>
</head>

<body>
    @php
        $etudiant = $soutenance->etudiant;
        $promotion = $etudiant->promotion ?? null;
        $formation = $promotion->formation ?? null;
        $parcours = $promotion->parcours ?? null;

        $membres = $soutenance->jury->membresJury
            ->sortBy(function ($membre) {
                return match (strtolower(trim($membre->fonction))) {
                    'president' => 1,
                    'rapporteur' => 2,
                    default => 3,
                };
            });

        $libellesFonctions = [
            'president' => 'Président',
            'rapporteur' => 'Rapporteur',
            'examinateur' => 'Examinateur',
        ];

        $nomComplet = trim($etudiant->user->name ?? '');

        $libelleDestinataire = match ($typeDestinataire) {
            'etudiant' => $nomComplet,
            default => $destinataire->name ?? '',
        };

        $fonctionMembre = $membreJury
            ? ($libellesFonctions[strtolower(trim($membreJury->fonction))] ?? $membreJury->fonction)
            : null;

        $anneeUniversitaire = $promotion->annee_universitaire
            ?? $soutenance->session->annee_universitaire
            ?? '20... / 20...';
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

    <div class="reference">
        Réf. : {{ $numeroConvocation }}<br>
        Année universitaire : {{ $anneeUniversitaire }}
    </div>

    <div class="titre-principal">Convocation à la soutenance de Master</div>

    <div class="destinataire">
        <strong>Destinataire :</strong> {{ $libelleDestinataire }}
        @if ($fonctionMembre)
            — {{ $fonctionMembre }} du jury
        @endif
    </div>

    @if ($typeDestinataire === 'etudiant')
        <div class="corps">
            Madame, Monsieur, vous êtes autorisé(e) à soutenir votre mémoire de Master.
            Nous avons l'honneur de vous convoquer à votre soutenance qui se tiendra selon
            les modalités suivantes :
        </div>
    @else
        <div class="corps">
            Madame, Monsieur, en votre qualité de membre du jury, nous vous convions à la
            soutenance de Master de l'étudiant(e) cité(e) ci-après, selon les modalités suivantes :
        </div>

        <table class="table-infos">
            <tr>
                <td class="libelle">Étudiant(e)</td>
                <td>{{ $nomComplet }}</td>
            </tr>
            <tr>
                <td class="libelle">Numéro étudiant</td>
                <td>{{ $etudiant->numero_etudiant }}</td>
            </tr>
        </table>
    @endif

    <table class="table-infos">
        <tr>
            <td class="libelle">Mémoire</td>
            <td>{{ $soutenance->titre_memoire }}</td>
        </tr>
        @if ($typeDestinataire === 'etudiant')
            <tr>
                <td class="libelle">Numéro étudiant</td>
                <td>{{ $etudiant->numero_etudiant }}</td>
            </tr>
        @endif
        <tr>
            <td class="libelle">Formation</td>
            <td>{{ $formation->nom ?? '—' }}@if($parcours) — Parcours {{ $parcours->nom }}@endif</td>
        </tr>
        <tr>
            <td class="libelle">Session de soutenance</td>
            <td>{{ $soutenance->session->nom ?? '—' }}</td>
        </tr>
        <tr>
            <td class="libelle">Date</td>
            <td>{{ ($soutenance->date_soutenance ?? $soutenance->creneau?->date_soutenance)?->format('d/m/Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="libelle">Heure</td>
            <td>
                @php
                    $debutPdf = $soutenance->heure_debut ?? $soutenance->creneau?->heure_debut;
                    $finPdf = $soutenance->heure_fin ?? $soutenance->creneau?->heure_fin;
                @endphp
                {{ $debutPdf ? (substr((string) $debutPdf, 0, 5) . ' - ' . substr((string) $finPdf, 0, 5)) : '—' }}
            </td>
        </tr>
        <tr>
            <td class="libelle">Salle</td>
            <td>
                @if ($soutenance->salle)
                    {{ $soutenance->salle->code }} — {{ $soutenance->salle->nom }}
                @else
                    —
                @endif
            </td>
        </tr>
    </table>

    @if ($soutenance->jury)
        <div class="jury">
            <strong>Membres du jury :</strong>
            @foreach ($membres as $membre)
                <p>
                    — {{ $membre->enseignant->user->name ?? $membre->enseignant->user->email ?? 'Membre' }}
                    ({{ $libellesFonctions[strtolower(trim($membre->fonction))] ?? $membre->fonction }})
                </p>
            @endforeach
        </div>
    @endif

    <div class="signature">
        Fait à Fianarantsoa, le {{ $dateGeneration->format('d/m/Y') }}<br><br>
        <strong>La Direction de l'E.M.I.T.</strong>
    </div>

    <div class="note-bas">
        Cette convocation est générée automatiquement par la plateforme de gestion des
        soutenances de Master de l'EMIT. Veuillez vous présenter 15 minutes avant l'heure
        indiquée, muni(e) d'une pièce d'identité. En cas d'empêchement, contactez
        impérativement la scolarité.
    </div>
</body>
</html>
