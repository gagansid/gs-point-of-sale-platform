<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\Data\OptionGroupData;
use App\Actions\Product\DeleteOptionGroup;
use App\Actions\Product\SaveOptionGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Product\OptionGroupRequest;
use App\Http\Resources\Api\V1\OptionGroupResource;
use App\Models\OptionGroup;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Katalog & produk')]
final class OptionGroupController extends Controller
{
    /**
     * Tambah grup opsi beserta opsinya.
     *
     * Permission product.manage. min_select 1 = wajib pilih (mis. Ukuran). `id` opsional sebagai idempotency key.
     */
    public function store(OptionGroupRequest $request, SaveOptionGroup $action): JsonResponse
    {
        $result = $action->handle(null, OptionGroupData::fromArray($request->validated()));

        return ApiResponse::success(
            OptionGroupResource::make($result['group'])->resolve($request),
            'Grup opsi berhasil ditambahkan',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Ubah grup opsi.
     *
     * Opsi dengan `id` diubah, tanpa `id` ditambah, opsi lama yang tidak dikirim dihapus.
     */
    public function update(OptionGroupRequest $request, OptionGroup $optionGroup, SaveOptionGroup $action): JsonResponse
    {
        $result = $action->handle($optionGroup, OptionGroupData::fromArray($request->safe()->except('id')));

        return ApiResponse::success(OptionGroupResource::make($result['group'])->resolve($request), 'Grup opsi berhasil diperbarui');
    }

    /**
     * Hapus grup opsi.
     *
     * Dilepas dari semua produk.
     */
    public function destroy(Request $request, OptionGroup $optionGroup, DeleteOptionGroup $action): JsonResponse
    {
        abort_unless($request->user()?->can('product.manage') ?? false, 403);

        $action->handle($optionGroup);

        return ApiResponse::success(null, 'Grup opsi berhasil dihapus');
    }
}
