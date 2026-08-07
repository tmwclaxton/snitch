<?php

namespace App\Mail\Marketing;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $message,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Snitch contact form',
            replyTo: [$this->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p><strong>From:</strong> '.e($this->name).' ('.e($this->email).')</p><p>'.nl2br(e($this->message)).'</p>',
        );
    }
}
