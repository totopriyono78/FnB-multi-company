<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Identity\Domain\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var CompanyUser $member */
        $member = $this->record;
        $member->loadMissing(['user.roles', 'scopes']);

        return $data + [
            'name' => $member->user->name,
            'email' => $member->user->email,
            'phone' => $member->user->phone,
            'roles' => $member->user->roles->pluck('name')->all(),
            'scope_outlets' => $member->scopes->where('scope_type', RoleScope::OUTLET)->pluck('scope_id')->values()->all(),
            'scope_brands' => $member->scopes->where('scope_type', RoleScope::BRAND)->pluck('scope_id')->values()->all(),
            'pin' => null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();
        /** @var CompanyUser $record */
        $payload = [
            'name' => $data['name'],
            'employee_code' => $data['employee_code'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'roles' => $data['roles'],
            'scopes' => ['outlets' => $data['scope_outlets'] ?? [], 'brands' => $data['scope_brands'] ?? []],
        ];
        if (! empty($data['pin'])) {
            $payload['pin'] = $data['pin'];
        }

        try {
            return app(StaffManager::class)->update($actor, $record, $payload);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($m, $k) => ['data.'.$k => $m])->all()
            );
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }
}
