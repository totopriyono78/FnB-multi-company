<?php

namespace App\Modules\Identity\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Pemberitahuan undangan bergabung ke company (FR-AUTH-04). */
class CompanyInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $invitationId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Undangan bergabung ke {$this->companyName}")
            ->greeting('Halo,')
            ->line("Anda diundang bergabung ke {$this->companyName} di FnB Cloud.")
            ->line('Buka aplikasi FnB Cloud, lalu terima atau tolak undangan di menu Undangan.')
            ->line('Abaikan email ini bila Anda tidak mengenal usaha tersebut.');
    }
}
