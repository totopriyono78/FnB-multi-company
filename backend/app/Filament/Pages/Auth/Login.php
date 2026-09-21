<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Demo\DemoAccounts;
use App\Modules\Identity\Application\LoginService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

/** Login back-office dengan email atau nomor HP + penguncian akun (FR-AUTH-01, FR-AUTH-08). */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email atau nomor HP')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /** Masuk langsung dengan akun demo yang diklik (hanya bila mode demo aktif). */
    public function loginAsDemo(string $email): ?LoginResponse
    {
        abort_unless(DemoAccounts::enabled() && DemoAccounts::has($email), 404);

        $this->form->fill([
            'email' => $email,
            'password' => DemoAccounts::PASSWORD,
            'remember' => false,
        ]);

        return $this->authenticate();
    }

    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();

        try {
            $user = app(LoginService::class)->attempt((string) $data['email'], (string) $data['password']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'data.email' => $e->validator->errors()->first('login'),
            ]);
        }

        Filament::auth()->login($user, (bool) ($data['remember'] ?? false));
        session()->regenerate();
        session()->put('fnb_auth_at', now()->getTimestamp());

        return app(LoginResponse::class);
    }
}
