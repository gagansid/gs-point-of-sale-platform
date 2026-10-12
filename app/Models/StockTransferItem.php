<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris transfer: qty dikirim & diterima (selisih = hilang/rusak di jalan).
 *
 * @property string $id
 * @property string $stock_transfer_id
 * @property string $product_id
 * @property string $product_name
 * @property int $qty_sent
 * @property int|null $qty_received
 * @property int $sort_order
 */
final class StockTransferItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['stock_transfer_id', 'product_id', 'product_name', 'qty_sent', 'sort_order'];

    protected function casts(): array
    {
        return ['qty_sent' => 'integer', 'qty_received' => 'integer', 'sort_order' => 'integer'];
    }
}
