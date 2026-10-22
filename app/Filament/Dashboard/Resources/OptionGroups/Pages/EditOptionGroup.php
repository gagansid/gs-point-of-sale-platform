<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Pages;

use App\Actions\Product\Data\OptionGroupData;
use App\Actions\Product\SaveOptionGroup;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\OptionGroups\OptionGroupResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\OptionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditOptionGroup extends EditRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = OptionGroupResource::class;

    protected static ?string $breadcrumb = 'Ubah';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['options'] = $record instanceof OptionGroup
            ? $record->options()->with('components')->get()->map(fn ($option): array => [
                'id' => $option->id,
                'name' => $option->name,
                'price_delta' => $option->price_delta,
                'is_default' => $option->is_default,
                // Bahan opsi (ADR 0020): form menampilkan satu bahan
                'component_id' => $option->components->first()?->component_id,
                'component_qty' => $option->components->first()?->qty,
            ])->all()
            : [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof OptionGroup) {
            return $record;
        }

        try {
            return app(SaveOptionGroup::class)->handle($record, OptionGroupData::fromArray([...$data, 'options' => array_values($data['options'] ?? [])]))['group'];
        } catch (BusinessException $e) {
            // Mis. opsi bawaan melebihi maksimal pilihan (Q129): tetap di form dengan pesan
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();

            throw $e;
        }
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Grup opsi berhasil diperbarui';
    }
}
