<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model bisnis tiruan untuk menguji BelongsToTenant sebelum tabel bisnis asli ada.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 */
final class TenantFixture extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'tenant_fixtures';

    protected $fillable = ['tenant_id', 'name'];

    public static function createTable(): void
    {
        Schema::create('tenant_fixtures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->timestamps();
        });
    }
}
