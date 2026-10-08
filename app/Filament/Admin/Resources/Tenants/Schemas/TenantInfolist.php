<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Enums\UserRole;
use App\Models\Tenant;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Detail tenant + statistik pemakaian (infolist.md).
 */
final class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Detail tenant')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')->label('Nama bisnis')->weight('semibold'),
                        TextEntry::make('slug')->label('Slug')->fontFamily('mono')->copyable(),
                        TextEntry::make('business_type')->label('Jenis usaha')->badge()->color('gray'),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('subscription_ends_at')
                            ->label('Langganan berakhir')
                            ->date('j M Y')
                            ->placeholder('Tanpa batas'),
                        TextEntry::make('created_at')->label('Terdaftar')->dateTime('j M Y, H.i'),
                    ]),

                Section::make('Pemakaian')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('outlets_count')->label('Outlet')
                            ->state(fn (Tenant $record): int => $record->outlets()->count())->numeric(locale: 'id'),
                        TextEntry::make('users_count')->label('Karyawan')
                            ->state(fn (Tenant $record): int => $record->users()->count())->numeric(locale: 'id'),
                        TextEntry::make('devices_count')->label('Perangkat')
                            ->state(fn (Tenant $record): int => $record->devices()->count())->numeric(locale: 'id'),
                        TextEntry::make('owners')
                            ->label('Owner')
                            ->state(fn (Tenant $record): array => $record->users()
                                ->where('role', UserRole::Owner)
                                ->pluck('email')
                                ->filter()
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
