<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Produk atau kategori yang termasuk program stempel (salah satu terisi).
 *
 * @property string $id
 * @property string $stamp_program_id
 * @property string|null $product_id
 * @property string|null $category_id
 */
final class StampProgramItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['stamp_program_id', 'product_id', 'category_id'];
}
