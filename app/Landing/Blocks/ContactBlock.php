<?php

namespace App\Landing\Blocks;

/** Formulario de contacto: crea una solicitud en Plataforma > Solicitudes. */
class ContactBlock extends LandingBlock
{
    public function type(): string
    {
        return 'contact';
    }

    public function label(): string
    {
        return 'Contacto';
    }

    public function icon(): string
    {
        return 'EnvelopeSimple';
    }

    public function help(): string
    {
        return 'Un formulario para que un mercado pida que lo contacten. Llega a Solicitudes.';
    }

    public function unique(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 200],
            'button_label' => ['type' => 'text', 'label' => 'Botón', 'max' => 30],
            'success_text' => ['type' => 'text', 'label' => 'Mensaje al enviar', 'max' => 140],
            'show_whatsapp' => ['type' => 'toggle', 'label' => 'Mostrar el WhatsApp de contacto'],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Hablemos',
            'text' => 'Déjanos tus datos y te llamamos para mostrarte la plataforma con tu mercado.',
            'button_label' => 'Quiero que me contacten',
            'success_text' => 'Listo. Te escribimos en menos de un día hábil.',
            'show_whatsapp' => true,
        ];
    }
}
