<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use App\Models\User;
use App\Support\ApiActor;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $actor = ApiActor::resolve($request);
        // Harga modal hanya untuk yang mengelola produk (kasir tidak perlu tahu margin)
        $canSeeCost = $actor instanceof User && $actor->can('product.manage');

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'price' => $this->price,
            'cost_price' => $this->when($canSeeCost, $this->cost_price),
            'track_stock' => $this->track_stock,
            'stock_qty' => $this->track_stock ? $this->stock_qty : null,
            'min_stock' => $this->track_stock ? $this->min_stock : null,
            'is_low_stock' => $this->isLowStock(),
            'is_active' => $this->is_active,
            'is_available' => $this->is_available,
            // Ditampilkan di tab "Favorit" layar kasir
            'is_favorite' => $this->is_favorite,
            'image_url' => $this->imageUrl(),
            // Urutan = urutan tampil grup opsi di aplikasi
            'option_group_ids' => $this->whenLoaded('optionGroups', fn () => $this->optionGroups->pluck('id')->values()->all()),
            'updated_at' => Iso::dateTime($this->updated_at),
        ];
    }
}
