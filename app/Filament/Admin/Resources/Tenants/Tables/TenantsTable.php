<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Tables;

use App\Enums\BusinessType;
use App\Filament\Admin\Resources\Tenants\Actions\TenantStatusActions;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\Edition;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar tenant (table.md, row-actions.md).
 */
final class TenantsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('users')->with('plan:id,name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nama bisnis')
                    ->weight('medium')
                    // Slug tidak ditampilkan, tetapi tetap bisa dicari
                    ->searchable(['name', 'slug'])
                    ->sortable(),
                TextColumn::make('business_type')
                    ->label('Jenis')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('plan.name')
                    ->label('Paket')
                    ->placeholder('Tanpa paket')
                    ->description(fn (Tenant $record): ?string => $record->billing_cycle?->getLabel())
                    ->visible(fn (): bool => Edition::isSaas())
                    ->toggleable(),
                TextColumn::make('subscription_ends_at')
                    ->label('Langganan berakhir')
                    ->date('j M Y')
                    ->placeholder('Tanpa batas')
                    // Merah: sudah lewat; kuning: berakhir dalam 7 hari
                    ->color(fn (Tenant $record): ?string => match (true) {
                        $record->subscription_ends_at === null => null,
                        $record->subscription_ends_at->isPast() => 'danger',
                        $record->subscription_ends_at->lte(now()->addDays(7)) => 'warning',
                        default => null,
                    })
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label('Karyawan')
                    ->numeric(locale: 'id')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Terdaftar')
                    ->date('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Audit: tersembunyi bawaan, tampilkan lewat pilih kolom
                ...AuditColumns::make(created: false),
            ])
            ->filters([
                SelectFilter::make('business_type')->label('Jenis usaha')->options(BusinessType::class),
                // "none" = tenant tanpa paket (semua fitur, ADR 0024)
                SelectFilter::make('plan_id')->label('Paket')
                    ->visible(fn (): bool => Edition::isSaas())
                    ->options(fn (): array => ['none' => 'Tanpa paket'] + Plan::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereNull('plan_id'),
                        default => $query->where('plan_id', $data['value']),
                    }),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah'),
                ActionGroup::make([
                    ViewAction::make()->label('Lihat detail'),
                    TenantStatusActions::suspend(),
                    TenantStatusActions::activate(),
                ])->tooltip('Aksi lain'),
            ])
            ->recordUrl(fn (Tenant $record): string => TenantResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc');

        return TableEmptyState::apply($table, Heroicon::OutlinedBuildingOffice2, 'tenant', 'Tambahkan bisnis pelanggan pertama beserta owner-nya');
    }
}
