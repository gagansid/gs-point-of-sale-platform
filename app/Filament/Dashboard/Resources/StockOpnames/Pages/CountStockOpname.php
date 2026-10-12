<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockOpnames\Pages;

use App\Actions\Stock\SaveOpnameCounts;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\StockOpname;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Lembar hitung stok opname (draf): isi qty fisik, simpan, lalu posting. */
final class CountStockOpname extends EditRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = StockOpnameResource::class;

    protected static ?string $breadcrumb = 'Isi hitungan';

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof StockOpname ? 'Stok opname '.$record->number : 'Stok opname';
    }

    protected function getHeaderActions(): array
    {
        return [StockOpnameActions::post(), StockOpnameActions::delete()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['counts'] = $record instanceof StockOpname ? $record->items()->get()->mapWithKeys(fn ($i): array => [(string) Str::uuid() => [
            'id' => $i->id, 'product_name' => $i->product_name, 'system_qty' => $i->system_qty, 'counted_qty' => $i->counted_qty,
        ]])->all() : [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof StockOpname) {
            return $record;
        }

        $counts = [];
        foreach ((array) ($data['counts'] ?? []) as $row) {
            if (filled($row['id'] ?? null)) {
                $counts[(string) $row['id']] = filled($row['counted_qty'] ?? null) ? (string) $row['counted_qty'] : null;
            }
        }

        try {
            return app(SaveOpnameCounts::class)->handle($record, $counts, (string) ($data['notes'] ?? ''));
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();

            throw $e;
        }
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Hitungan disimpan';
    }
}
