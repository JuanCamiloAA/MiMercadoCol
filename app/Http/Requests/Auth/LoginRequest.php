<?php

namespace App\Http\Requests\Auth;

use App\Models\AccessLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El formulario dice con que credencial se entra. Si no lo dice, se deduce de lo que venga lleno.
     */
    protected function prepareForValidation(): void
    {
        $mode = $this->input('login_mode');

        if (! in_array($mode, ['email', 'document'], true)) {
            $mode = filled($this->input('document')) ? 'document' : 'email';
        }

        $this->merge([
            'login_mode' => $mode,
            'document' => is_string($this->input('document')) ? trim($this->input('document')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'login_mode' => ['required', 'string', 'in:email,document'],
            'email' => ['required_if:login_mode,email', 'nullable', 'string', 'email'],
            'document' => ['required_if:login_mode,document', 'nullable', 'string', 'max:40'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required_if' => 'El correo es obligatorio.',
            'email.email' => 'El correo no es valido.',
            'document.required_if' => 'El documento es obligatorio.',
            'password.required' => 'La contrasena es obligatoria.',
        ];
    }

    public function isDocumentLogin(): bool
    {
        return $this->input('login_mode') === 'document';
    }

    /** Campo al que se cuelgan los errores, para que salgan bajo el input visible. */
    public function credentialField(): string
    {
        return $this->isDocumentLogin() ? 'document' : 'email';
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $field = $this->credentialField();

        $authenticated = $this->isDocumentLogin()
            ? $this->attemptWithDocument()
            : Auth::attempt(['email' => $this->input('email'), 'password' => $this->input('password')], $this->boolean('remember'));

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());

            AccessLog::log(AccessLog::ACTION_LOGIN_FAILED, null, [
                'mode' => $this->input('login_mode'),
                'credential' => $this->isDocumentLogin() ? $this->input('document') : $this->input('email'),
            ]);

            throw ValidationException::withMessages([
                $field => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        $user = Auth::user();

        if ($user && ! $user->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                $field => 'Tu cuenta esta desactivada. Contacta al administrador.',
            ]);
        }

        if ($user && ! $user->isSuperAdmin()) {
            if (! $user->company_id) {
                Auth::logout();

                throw ValidationException::withMessages([
                    $field => 'Tu cuenta no esta asociada a ninguna empresa.',
                ]);
            }

            $company = Company::query()->find($user->company_id);

            if (! $company || ! $company->is_active) {
                Auth::logout();

                throw ValidationException::withMessages([
                    $field => 'La empresa esta desactivada o ya no existe. Contacta al soporte.',
                ]);
            }
        }

        $user?->forceFill(['last_login_at' => now()])->save();

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Entrada con documento.
     *
     * El documento solo es unico dentro de cada empresa, asi que puede haber varios
     * candidatos: se prueba la contrasena contra cada uno y entra el que coincida. Nunca se
     * revela si el documento existe; el mensaje de fallo es siempre el mismo.
     */
    private function attemptWithDocument(): bool
    {
        $document = (string) $this->input('document');

        if ($document === '') {
            return false;
        }

        $candidates = User::query()->where('document', $document)->get();

        foreach ($candidates as $candidate) {
            if (Auth::attempt(['id' => $candidate->id, 'password' => $this->input('password')], $this->boolean('remember'))) {
                return true;
            }
        }

        return false;
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            $this->credentialField() => "Demasiados intentos. Intenta de nuevo en {$seconds} segundos.",
        ]);
    }

    /** Se limita por la credencial usada, sea correo o documento. */
    public function throttleKey(): string
    {
        $identifier = $this->isDocumentLogin()
            ? 'doc:'.$this->string('document')
            : (string) $this->string('email');

        return Str::transliterate(Str::lower($identifier).'|'.$this->ip());
    }
}
