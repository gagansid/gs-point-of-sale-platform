<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Membatasi data per outlet (order, shift, perangkat) ke outlet yang boleh diakses (ADR 0010, Q38).
 *
 * Berlaku bila CurrentOutlet terisi (request API/dashboard): user tanpa outlet tidak melihat data
 * apa pun (fail-closed), dan route model binding ke outlet lain menjadi 404. Tanpa konteks
 * (command, job, panel /admin) hanya TenantScope yang berlaku.
 */
final class OutletScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $ids = CurrentOutlet::accessibleIds();

        if ($ids !== null) {
            $builder->whereIn($model->qualifyColumn('outlet_id'), $ids);
        }
    }
}
