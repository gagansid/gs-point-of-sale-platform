<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Pages;

use App\Actions\Product\Data\OptionGroupData;
use App\Actions\Product\SaveOptionGroup;
use App\Filament\Dashboard\Resources\OptionGroups\OptionGroupResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateOptionGroup extends CreateRecord
{
    protected static string $resource = OptionGroupResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Tambah grup opsi';

    protected static ?string $breadcrumb = 'Tambah';

    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveOptionGroup::class)->handle(null, OptionGroupData::fromArray([...$data, 'options' => array_values($data['options'] ?? [])]))['group'];
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Grup opsi berhasil ditambahkan';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
