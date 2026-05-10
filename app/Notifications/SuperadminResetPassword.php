<?php
namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class SuperadminResetPassword extends Notification
{
    public $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $url = url(route('superadmin.password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()], false));

        return (new MailMessage)
                    ->subject('Reset Superadmin Password')
                    ->line('You are receiving this email because we received a password reset request for your superadmin account.')
                    ->action('Reset Superadmin Password', $url)
                    ->line('If you did not request a password reset, no further action is required.');
    }

    public function toArray($notifiable)
    {
        return ['token' => $this->token];
    }
}
