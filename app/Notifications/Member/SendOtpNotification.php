<?php

namespace App\Notifications\Member;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendOtpNotification extends Notification
{
    use Queueable;

    const SUBJECTS = [
        'email'          => '驗證您的 Email',
        'password_reset' => '密碼重設驗證碼',
        'email_change'   => '變更 Email 驗證碼',
    ];

    public function __construct(
        public string $code,
        public string $type,
        public int $expireMinutes = 5,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECTS[$this->type] ?? '驗證碼')
            ->greeting("Hi " . ($notifiable->name ?? ''))
            ->line('您的驗證碼是：')
            ->line("**{$this->code}**")
            ->line("此驗證碼於 {$this->expireMinutes} 分鐘後失效。")
            ->line('若非本人操作請忽略此信。');
    }
}
