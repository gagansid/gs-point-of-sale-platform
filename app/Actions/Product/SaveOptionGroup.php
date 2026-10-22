<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Product\Data\OptionGroupData;
use App\Enums\ErrorCode;
use App\Enums\ProductKind;
use App\Exceptions\BusinessException;
use App\Models\Option;
use App\Models\OptionComponent;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Membuat/mengubah grup opsi sekaligus opsinya: opsi ber-id diubah, tanpa id dibuat,
 * opsi lama yang tidak dikirim dihapus (soft delete; snapshot order tetap utuh). Opsi bawaan (Q129) maks. sejumlah
 * maksimal pilihan grup.
 */
final class SaveOptionGroup
{
    /**
     * @return array{group: OptionGroup, replayed: bool}
     */
    public function handle(?OptionGroup $group, OptionGroupData $data): array
    {
        if ($group === null && ($existing = Idempotency::existing(OptionGroup::class, $data->id)) !== null) {
            return ['group' => $existing->load('options'), 'replayed' => true];
        }

        // Bawaan tidak boleh melebihi jumlah maksimal pilihan (pilih-satu = maks. 1 bawaan), Q129
        $defaults = count(array_filter($data->options, fn (array $o): bool => $o['is_default'] === true));
        if ($defaults > $data->maxSelect) {
            throw BusinessException::of(ErrorCode::ValidationError,
                $data->maxSelect === 1 ? 'Pilih satu opsi bawaan saja' : "Opsi bawaan maksimal {$data->maxSelect}",
                ['options' => ['Opsi bawaan melebihi maksimal pilihan']]);
        }

        $group = DB::transaction(function () use ($group, $data): OptionGroup {
            $isNew = $group === null;
            $group ??= new OptionGroup;

            if ($isNew && $data->id !== null) {
                $group->id = $data->id;
            }

            $group->fill([
                'name' => $data->name,
                'min_select' => $data->minSelect,
                'max_select' => $data->maxSelect,
                'is_active' => $data->isActive,
            ])->save();

            $keep = [];
            foreach ($data->options as $order => $input) {
                // Opsi hanya dicari di grup ini: id opsi grup/tenant lain diperlakukan sebagai opsi baru
                $option = $input['id'] !== null
                    ? Option::query()->where('option_group_id', $group->id)->find($input['id'])
                    : null;
                $option ??= new Option(['option_group_id' => $group->id]);

                $option->fill([
                    'name' => $input['name'],
                    'price_delta' => $input['price_delta'],
                    'sort_order' => $order,
                    ...($input['is_default'] !== null ? ['is_default' => $input['is_default']] : []),
                ])->save();

                if ($input['components'] !== null) {
                    self::syncComponents($option, $input['components']);
                }

                $keep[] = $option->id;
            }

            Option::query()->where('option_group_id', $group->id)->whereNotIn('id', $keep)->delete();

            return $group;
        });

        return ['group' => $group->load('options'), 'replayed' => false];
    }

    /**
     * Bahan opsi (ADR 0020): hanya produk tenant ini yang bukan paket; lainnya diabaikan.
     *
     * @param  list<array{component_id: string, qty: int}>  $components
     */
    private static function syncComponents(Option $option, array $components): void
    {
        OptionComponent::query()->where('option_id', $option->id)->delete();
        $valid = Product::query()->whereIn('id', array_column($components, 'component_id'))
            ->where('kind', '!=', ProductKind::Bundle->value)->pluck('id')->all();

        foreach ($components as $row) {
            if (in_array($row['component_id'], $valid, true)) {
                OptionComponent::query()->create(['option_id' => $option->id, 'component_id' => $row['component_id'], 'qty' => min($row['qty'], 1_000_000)]);
            }
        }
    }
}
