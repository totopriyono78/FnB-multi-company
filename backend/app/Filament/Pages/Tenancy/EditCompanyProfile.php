<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\EditTenantProfile;
use Illuminate\Database\Eloquent\Model;

/** Profil company (FR-TEN-03). */
class EditCompanyProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Profil Usaha';
    }

    public static function canView(Model $tenant): bool
    {
        return auth()->user()?->can('update', $tenant) ?? false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identitas')->columns(2)->schema([
                TextInput::make('name')->label('Nama usaha')->required()->maxLength(120),
                TextInput::make('legal_name')->label('Nama badan usaha')->placeholder('PT / CV')->maxLength(150),
                TextInput::make('npwp')->label('NPWP')->maxLength(25)
                    ->regex('/^(\d{15}|\d{16}|\d{2}\.\d{3}\.\d{3}\.\d-\d{3}\.\d{3})$/')
                    ->validationMessages(['regex' => 'Format NPWP tidak valid (15/16 digit).']),
                TextInput::make('email')->label('Email')->email(),
                TextInput::make('phone')->label('Telepon')->tel()->maxLength(20),
            ]),
            Section::make('Alamat')->columns(2)->schema([
                Textarea::make('address')->label('Alamat')->rows(2)->columnSpanFull(),
                TextInput::make('city')->label('Kota'),
                TextInput::make('province')->label('Provinsi'),
                TextInput::make('postal_code')->label('Kode pos')->regex('/^\d{5}$/'),
                Select::make('timezone')->label('Zona waktu')->options([
                    'Asia/Jakarta' => 'WIB (Asia/Jakarta)',
                    'Asia/Makassar' => 'WITA (Asia/Makassar)',
                    'Asia/Jayapura' => 'WIT (Asia/Jayapura)',
                ])->required(),
            ]),
            Section::make('Dukungan teknis')->schema([
                Toggle::make('allow_support_access')
                    ->label('Izinkan tim FnB Cloud membuka data untuk membantu kendala')
                    ->helperText('Setiap akses tim dukungan tercatat di audit log.'),
            ]),
        ]);
    }
}
