<!DOCTYPE html>
<html lang="fr">
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #1f2937; line-height: 1.5;">
    <p>Bonjour <?php echo e($nomUtilisateur); ?>,</p>

    <p>Vous avez demandé la réinitialisation de votre mot de passe sur la plateforme
        de gestion des soutenances de Master (EMIT).</p>

    <p style="font-size: 22px; font-weight: bold; letter-spacing: 4px;"><?php echo e($code); ?></p>

    <p>Ce code est valable <?php echo e($dureeMinutes); ?> minutes. Si vous n'êtes pas à l'origine de
        cette demande, ignorez simplement cet e-mail.</p>

    <p>— Plateforme Gestion de Soutenance Master (EMIT)</p>
</body>
</html>
<?php /**PATH I:\Stage Emit L3\Gestion-Soutenance-Master-main\backend\resources\views/emails/code-reinitialisation.blade.php ENDPATH**/ ?>