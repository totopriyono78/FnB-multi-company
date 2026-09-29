<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use App\Modules\Identity\Application\PhoneNumber;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    protected static bool $canCreateAnother = false;

    private bool $passwordDiberikanAdmin = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();
        $this->passwordDiberikanAdmin = filled($data['password'] ?? null);

        try {
            return app(StaffManager::class)->create($actor, [
                'name' => $data['name'],
                'email' => mb_strtolower(trim((string) $data['email'])),
                'phone' => PhoneNumber::normalize($data['phone'] ?? null),
                'employee_code' => $data['employee_code'] ?? null,
                'roles' => $data['roles'],
                'scopes' => ['outlets' => $data['scope_outlets'] ?? [], 'brands' => $data['scope_brands'] ?? []],
                'pin' => $data['pin'] ?? null,
                'password' => $data['password'] ?? null,
            ]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($m, $k) => ['data.'.$k => $m])->all()
            );
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            throw new Halt;
        }
    }

    public function getTitle(): string
    {
        return 'Tambah Staf';
    }

    /**
     * Tiga jalur berbeda, tiga langkah lanjutan yang berbeda pula bagi admin — dan hanya satu
     * dari ketiganya yang benar-benar terjadi. Tanpa diberi tahu di sini, admin baru sadar
     * stafnya tidak bisa masuk berhari-hari kemudian.
     */
    protected function getCreatedNotification(): ?Notification
    {
        /** @var CompanyUser $member */
        $member = $this->record;

        return Notification::make()->success()->persistent()
            ->title('Staf ditambahkan')
            ->body(match (true) {
                $member->invited_at !== null => 'Email ini sudah punya akun, jadi yang dikirim adalah undangan. '
                    .'Staf masuk memakai password akunnya sendiri, bukan password baru.',
                $this->passwordDiberikanAdmin => 'Sampaikan password awalnya kepada staf. '
                    .'Ia wajib menggantinya saat pertama kali masuk.',
                default => 'Tautan pembuatan password dikirim ke email staf. Bila pengiriman email belum diatur, '
                    .'buka kembali data staf ini dan isi kolom password awal.',
            });
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Staf');
    }
}
