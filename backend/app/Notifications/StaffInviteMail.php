<?php

namespace App\Notifications;

use App\Models\Store;
use App\Tenancy\Permissions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitation for a merchant's staff member (Chinese — sent to the merchant's team). Queued. */
class StaffInviteMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Store $store,
        public string $inviterName,
        public string $url,
        public array $permissions,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'Lumée'),
            subject: __(':p0 邀请你加入「:p1」的店铺后台', ['p0' => $this->inviterName, 'p1' => $this->store->name]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.staff_invite', with: [
            'labels' => array_map(fn ($p) => Permissions::ALL[$p][0], $this->permissions),
            'days' => \App\Models\StoreInvitation::VALID_DAYS,
        ]);
    }
}
