<?php

namespace App\Mail;

use App\Models\DailyBrief;
use App\Support\DailyBriefPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyBriefMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public DailyBrief $brief)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $date = $this->brief->brief_date?->toDateString() ?? 'today';

        return new Envelope(
            subject: 'Your Snitch daily summary for '.$date,
        );
    }

    public function content(): Content
    {
        $payload = app(DailyBriefPresenter::class)->payload($this->brief);

        return new Content(
            markdown: 'mail.daily-brief',
            with: [
                'headline' => (string) ($payload['headline'] ?? ''),
                'body' => app(DailyBriefPresenter::class)->toPlainText($payload),
                'url' => DailyBriefPresenter::appUrl($this->brief->brief_date?->toDateString()),
            ],
        );
    }
}
