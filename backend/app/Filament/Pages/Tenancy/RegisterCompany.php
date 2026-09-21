<?php

namespace App\Filament\Pages\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\CompanyRegistrar;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\RegisterTenant;
use Illuminate\Database\Eloquent\Model;

/** Langkah pertama wizard onboarding: buat company (FR-TEN-01, FR-TEN-11). */
class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Daftarkan Usaha';
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Nama usaha')->placeholder('mis. Kopi Tepi Jalan')->required()->maxLength(120),
            TextInput::make('city')->label('Kota')->maxLength(80),
            Select::make('timezone')->label('Zona waktu')->options([
                'Asia/Jakarta' => 'WIB (Asia/Jakarta)',
                'Asia/Makassar' => 'WITA (Asia/Makassar)',
                'Asia/Jayapura' => 'WIT (Asia/Jayapura)',
            ])->default('Asia/Jakarta')->required(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRegistration(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return app(CompanyRegistrar::class)->register([
            'name' => (string) $data['name'],
            'city' => $data['city'] ?? null,
            'timezone' => $data['timezone'] ?? 'Asia/Jakarta',
            'email' => $user->email,
        ], $user);
    }
}
