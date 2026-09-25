<?php

namespace App\Mail;

use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Registration $registration,
        public string $qrPng,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your QR pass: '.$this->registration->event->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.registration-confirmation',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->qrPng, 'attendee-qr-pass.png')
                ->withMime('image/png'),
        ];
    }
}
