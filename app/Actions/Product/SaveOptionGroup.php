<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Product\Data\OptionGroupData;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Membuat/mengubah grup opsi sekaligus opsinya: opsi ber-id diubah, tanpa id dibuat,
 * opsi lama yang tidak dikirim dihapus (soft delete; snapshot order tetap utuh).
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
                ])->save();

                $keep[] = $option->id;
            }

            Option::query()->where('option_group_id', $group->id)->whereNotIn('id', $keep)->delete();

            return $group;
        });

        return ['group' => $group->load('options'), 'replayed' => false];
    }
}
