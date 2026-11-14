<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};

class StoreMessage extends Mailable
{
    public function __construct(public string $heading, public string $messageText, public string $actionPath) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.store');
    }
}
