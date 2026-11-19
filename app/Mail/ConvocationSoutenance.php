<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Convocation individuelle à une soutenance de Master.
 * Le PDF officiel de la convocation est joint au message.
 */
class ConvocationSoutenance extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nomDestinataire,
        public readonly string $titreMemoire,
        public readonly string $nomEtudiant,
        public readonly string $date,
        public readonly string $heure,
        public readonly string $salle,
        public readonly string $pdfBytes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Convocation à une soutenance de Master — ' . $this->titreMemoire,
        );
    }

    public function content(): Content
    {
        return new Content(html: 'emails.convocation');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBytes)
                ->as('convocation-soutenance.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
