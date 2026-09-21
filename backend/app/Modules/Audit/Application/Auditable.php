<?php

namespace App\Modules\Audit\Application;

use Illuminate\Database\Eloquent\Model;

/**
 * Mencatat perubahan master data ke audit log (FR-AUD-01).
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        $ignored = ['created_at', 'updated_at', 'updated_by', 'created_by'];
        $prefix = fn (Model $m) => strtolower(class_basename($m));

        static::created(function (Model $model) use ($ignored, $prefix): void {
            app(AuditLogger::class)->log(
                $prefix($model).'.created',
                $model,
                new: array_diff_key($model->attributesToArray(), array_flip($ignored)),
            );
        });

        static::updated(function (Model $model) use ($ignored, $prefix): void {
            $changes = array_diff_key($model->getChanges(), array_flip($ignored));
            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);
            app(AuditLogger::class)->log($prefix($model).'.updated', $model, $old, $changes);
        });

        static::deleted(function (Model $model) use ($prefix): void {
            app(AuditLogger::class)->log($prefix($model).'.deleted', $model);
        });
    }
}
