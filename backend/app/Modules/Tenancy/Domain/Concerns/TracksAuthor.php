<?php

namespace App\Modules\Tenancy\Domain\Concerns;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Mengisi kolom created_by / updated_by (SRS §6.3 butir 3).
 *
 * @mixin Model
 */
trait TracksAuthor
{
    public static function bootTracksAuthor(): void
    {
        $actorId = static function (): ?string {
            $user = auth()->user();

            return $user instanceof User ? $user->id : null;
        };

        static::creating(function (Model $model) use ($actorId): void {
            $model->setAttribute('created_by', $model->getAttribute('created_by') ?? $actorId());
            $model->setAttribute('updated_by', $model->getAttribute('updated_by') ?? $actorId());
        });

        static::updating(function (Model $model) use ($actorId): void {
            $model->setAttribute('updated_by', $actorId() ?? $model->getAttribute('updated_by'));
        });
    }
}
