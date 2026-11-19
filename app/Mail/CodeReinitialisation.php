<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Code de réinitialisation de mot de passe (6 chiffres) envoyé par
 * e-mail au demandeur.
 */
class CodeReinitialisation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nomUtilisateur,
        public readonly string $code,
        public readonly int $dureeMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Réinitialisation de votre mot de passe — Soutenances Master',
        );
    }

    public function content(): Content
    {
        return new Content(html: 'emails.code-reinitialisation');
    }
}
