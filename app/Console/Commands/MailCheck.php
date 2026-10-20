<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manda un correo de prueba por el mailer por defecto, el mismo que usan todas las notificaciones.
 * Si Brevo rechaza el usuario, la clave o el remitente, aqui sale su respuesta tal cual.
 */
class MailCheck extends Command
{
    protected $signature = 'mail:check {to : Correo que recibe la prueba}';

    protected $description = 'Envía un correo de prueba por el mailer por defecto (Brevo) y muestra la respuesta del servidor';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("«{$to}» no es un correo válido.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $config = (array) config("mail.mailers.{$mailer}");

        $this->line("Mailer: {$mailer}");

        if (($config['transport'] ?? null) === 'smtp') {
            $this->line('Servidor: '.$config['host'].':'.$config['port']);
            $this->line('Login: '.($config['username'] ?: '(falta BREVO_SMTP_LOGIN)'));
            $this->line('Clave: '.(filled($config['password'] ?? null) ? 'configurada' : '(falta BREVO_SMTP_KEY)'));
        }

        $this->line('Remitente: '.config('mail.from.name').' <'.config('mail.from.address').'>');
        $this->newLine();

        try {
            Mail::raw(
                'Este es un correo de prueba de '.config('app.name').', enviado el '.now()->format('d/m/Y h:i a').'. Si lo recibes, el envío por '.$mailer.' funciona.',
                fn (Message $message) => $message->to($to)->subject('Prueba de correo · '.config('app.name')),
            );
        } catch (Throwable $exception) {
            $this->error('No salió: '.Str::limit($exception->getMessage(), 500));

            return self::FAILURE;
        }

        $this->info("El servidor aceptó el correo para {$to}. Revisa la bandeja (y la de spam).");

        return self::SUCCESS;
    }
}
