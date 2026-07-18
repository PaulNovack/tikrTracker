<?php

namespace App\Mail;

use App\Models\PriceAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PriceAlertNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public PriceAlert $alert, public string $direction, public float $currentPrice)
    {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $emoji = $this->direction === 'up' ? '📈' : '📉';
        $subject = "{$emoji} {$this->alert->asset->symbol} Price Alert: ".($this->direction === 'up' ? 'Above' : 'Below').' Threshold';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.price-alert',
            with: [
                'alert' => $this->alert,
                'direction' => $this->direction,
                'currentPrice' => $this->currentPrice,
                'symbol' => $this->alert->asset->symbol,
                'commonName' => $this->alert->asset->common_name,
                'basePrive' => $this->alert->base_price,
                'triggerPrice' => $this->direction === 'up' ? $this->alert->above_price : $this->alert->below_price,
                'percentage' => $this->direction === 'up' ? $this->alert->up_percentage : $this->alert->down_percentage,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
