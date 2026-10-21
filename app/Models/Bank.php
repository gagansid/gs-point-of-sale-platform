<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Master bank platform (Q124, Sistem → Bank). Ditulis lewat App\Actions\Admin\SaveBank / SetBankActive.
 * Logo: unggahan (disk public) bila ada, selain itu logo vektor dari singkatan & warna merek (komponen x-bank-logo).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $short_name
 * @property string $color hex #rrggbb
 * @property string $text_color hex #rrggbb
 * @property string|null $logo_path
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Bank extends Model
{
    use HasUuids, LogsChanges, RecordsAuthor;

    protected $attributes = ['is_active' => true, 'sort_order' => 0, 'color' => '#2d4282', 'text_color' => '#ffffff'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public static function authorModel(): string
    {
        return Admin::class;
    }

    /** @return list<string> */
    public static function auditAttributes(): array
    {
        return ['code', 'name', 'short_name', 'color', 'text_color', 'logo_path', 'is_active', 'sort_order'];
    }

    /** Pelaku = super admin login (guard admin; resolver bawaan hanya membaca guard default). */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $admin = auth('admin')->user();
        if ($activity instanceof Model && $activity->getAttribute('causer_id') === null && $admin instanceof Admin) {
            $activity->causer()->associate($admin);
        }
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Pilihan Select berlogo (allowHtml): logo 20px + nama. Bank aktif terurut; $search menyaring nama/singkatan.
     *
     * @return array<string, string>
     */
    public static function htmlOptions(?string $search = null): array
    {
        return self::query()->active()
            ->when(filled($search), fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', '%'.$search.'%')->orWhere('short_name', 'like', '%'.$search.'%')))
            ->orderBy('sort_order')->orderBy('name')->limit(50)->get()
            ->mapWithKeys(fn (self $b): array => [$b->id => self::optionHtml($b)])->all();
    }

    public static function optionHtml(self $bank): string
    {
        return '<span class="gs-bank-option">'.Blade::render('<x-bank-logo :bank="$bank" :size="20" />', ['bank' => $bank])
            .'<span class="gs-bank-option-name">'.e($bank->name).'</span></span>';
    }

    /** @return array<string, string> id → nama, bank aktif terurut */
    public static function options(): array
    {
        return self::query()->active()->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (self $b): array => [$b->id => $b->short_name.' · '.$b->name])->all();
    }
}
