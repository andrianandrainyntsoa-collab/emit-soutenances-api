<!DOCTYPE html>
<html lang="fr">
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #1f2937; line-height: 1.5;">
    <p>Bonjour {{ $nomDestinataire }},</p>

    <p>Vous êtes convié(e) à la soutenance de Master
        <strong>« {{ $titreMemoire }} »</strong>.</p>

    <table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; border-color: #9ca3af;">
        <tr><td><strong>Étudiant(e)</strong></td><td>{{ $nomEtudiant }}</td></tr>
        <tr><td><strong>Date</strong></td><td>{{ $date }}</td></tr>
        <tr><td><strong>Heure</strong></td><td>{{ $heure }}</td></tr>
        <tr><td><strong>Salle</strong></td><td>{{ $salle }}</td></tr>
    </table>

    <p>La convocation officielle est jointe à cet e-mail au format PDF.</p>

    <p>— Plateforme Gestion de Soutenance Master (EMIT)</p>
</body>
</html>
