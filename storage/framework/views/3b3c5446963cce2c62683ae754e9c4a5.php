<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Compte rendu des résultats</title>

    <style>
        /* Document récapitulatif compact : tient sur une page A4 recto
           pour un volume courant de résultats. */
        @page {
            size: A4 portrait;
            margin: 13mm 14mm 11mm 14mm;
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 10pt;
            color: #000;
            line-height: 1.25;
        }

        .entete {
            width: 100%;
            border: 1px solid #000;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 12px;
        }

        .entete td {
            border: none;
            vertical-align: middle;
            padding: 5px 4px;
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
            font-weight: bold;
            text-transform: uppercase;
            text-decoration: underline;
            margin: 10px 0 6px;
        }

        .sous-titre {
            text-align: center;
            font-size: 9.5pt;
            margin-bottom: 10px;
            color: #222;
        }

        table.resultats {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }

        table.resultats th {
            border: 1px solid #000;
            padding: 4px 5px;
            background-color: #eee;
            text-align: left;
        }

        table.resultats td {
            border: 1px solid #000;
            padding: 4px 5px;
            vertical-align: top;
        }

        .col-num { width: 5%; text-align: center; }
        .col-nom { width: 22%; }
        .col-titre { width: 34%; }
        .col-note { width: 12%; text-align: center; }
        .col-decision { width: 14%; text-align: center; }
        .col-mention { width: 13%; text-align: center; }

        .aucun {
            text-align: center;
            font-style: italic;
            margin: 20px 0;
        }

        .pied {
            margin-top: 16px;
            font-size: 8.5pt;
            font-style: italic;
            text-align: justify;
        }

        .signature {
            margin-top: 18px;
            text-align: right;
            font-size: 10pt;
        }
    </style>
</head>

<body>
    <?php
        $mentionParDefaut = fn ($r) => $r->mention ?? '—';
    ?>

    
    <table class="entete">
        <tr>
            <td class="logo-cell">
                <img class="logo" src="<?php echo e(public_path('images/logo-universite.png')); ?>" alt="Université de Fianarantsoa">
            </td>
            <td class="texte-entete">
                <div>UNIVERSITE DE FIANARANTSOA</div>
                <div>ÉCOLE DE MANAGEMENT ET D'INNOVATION TECHNOLOGIQUE</div>
                <div><strong>E.M.I.T.</strong></div>
                <div>Tel: 034.87.042.16 - 033.70.470.00 - BP: 1135 — www.emit.mg</div>
            </td>
            <td class="logo-cell">
                <img class="logo" src="<?php echo e(public_path('images/logo-emit.png')); ?>" alt="EMIT">
            </td>
        </tr>
    </table>

    <div class="titre-principal">Compte rendu des résultats</div>

    <div class="sous-titre">
        Soutenances de Master — Résultats définitifs
        <?php if($resultats->first()?->soutenance?->session?->nom): ?>
            — Session « <?php echo e($resultats->first()->soutenance->session->nom); ?> »
        <?php endif; ?>
        <br>
        Édité le <?php echo e($dateGeneration->format('d/m/Y à H:i')); ?>

    </div>

    <?php if($resultats->isEmpty()): ?>
        <div class="aucun">
            Aucun résultat définitif n'est encore disponible dans le système.
        </div>
    <?php else: ?>
        <table class="resultats">
            <thead>
                <tr>
                    <th class="col-num">N°</th>
                    <th class="col-nom">Étudiant(e)</th>
                    <th class="col-titre">Titre du mémoire</th>
                    <th class="col-note">Note retenue</th>
                    <th class="col-decision">Décision finale</th>
                    <th class="col-mention">Mention</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $resultats; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr>
                        <td class="col-num"><?php echo e($loop->iteration); ?></td>
                        <td class="col-nom">
                            <?php echo e($r->soutenance?->etudiant?->user?->name ?? '—'); ?>

                            <?php if($r->soutenance?->etudiant?->numero_etudiant): ?>
                                <br><span style="font-size:8pt">(<?php echo e($r->soutenance->etudiant->numero_etudiant); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-titre"><?php echo e($r->soutenance?->titre_memoire ?? '—'); ?></td>
                        <td class="col-note"><?php echo e($r->moyenne_finale !== null ? number_format((float) $r->moyenne_finale, 2, ',', ' ') . ' / 20' : '—'); ?></td>
                        <td class="col-decision"><?php echo e(ucfirst($r->decision)); ?></td>
                        <td class="col-mention"><?php echo e($mentionParDefaut($r)); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="pied">
        Document généré automatiquement à partir des données réelles de la plateforme de
        gestion des soutenances de Master (EMIT). Les résultats listés sont définitifs
        (validés par le président du jury).
    </div>

    <div class="signature">
        Fait à Fianarantsoa, le <?php echo e($dateGeneration->format('d/m/Y')); ?>

    </div>
</body>
</html>
<?php /**PATH I:\Stage Emit L3\Gestion-Soutenance-Master-main\backend\resources\views/pdf/compte-rendu-resultats.blade.php ENDPATH**/ ?>