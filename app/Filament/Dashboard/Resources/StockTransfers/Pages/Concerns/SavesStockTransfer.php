<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockTransfers\Pages\Concerns;

use App\Actions\Stock\SaveStockTransfer;
use App\Exceptions\BusinessException;
use App\Models\StockTransfer;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/** Simpan draf transfer dari form (create & edit): error bisnis tampil sebagai notifikasi, form tetap terbuka. */
trait SavesStockTransfer
{
    /** @param array<string, mixed> $data */
    protected function saveTransfer(?StockTransfer $transfer, array $data): StockTransfer
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            return app(SaveStockTransfer::class)->handle($transfer, $user, [
                'id' => $transfer === null ? (string) Str::uuid7() : null,
                'from_outlet_id' => (string) ($data['from_outlet_id'] ?? $transfer->from_outlet_id ?? ''),
                'to_outlet_id' => (string) ($data['to_outlet_id'] ?? ''),
                'notes' => $data['notes'] ?? null,
                'items' => array_values(array_map(fn (array $i): array => [
                    'product_id' => (string) ($i['product_id'] ?? ''), 'qty' => (int) ($i['qty'] ?? 0),
                ], (array) ($data['items'] ?? []))),
            ]);
        } catch (BusinessException $e) {
            $details = is_array($e->details) ? implode(' ', array_merge(...array_values(array_map(fn (mixed $m): array => array_map('strval', (array) $m), $e->details)))) : null;
            Notification::make()->danger()->title($e->getMessage())->body($details !== '' ? $details : null)->send();
            $this->halt();

            throw $e;
        }
    }
}
