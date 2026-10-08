<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Form tenant (form.md). Saat membuat: sekaligus outlet & owner pertama.
 * Saat mengubah: hanya profil & masa langganan; status lewat aksi Tangguhkan/Aktifkan.
 */
final class TenantForm
{
    /** Zona waktu Indonesia yang didukung (ADR 0001). */
    public const TIMEZONES = [
        'Asia/Jakarta' => 'WIB — Asia/Jakarta',
        'Asia/Makassar' => 'WITA — Asia/Makassar',
        'Asia/Jayapura' => 'WIT — Asia/Jayapura',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Informasi bisnis')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama bisnis')
                            ->placeholder('Kopi Senja')
                            ->required()
                            ->maxLength(100)
                            ->autofocus()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                                // Slug otomatis hanya saat membuat dan belum diubah manual
                                if ($operation === 'create' && blank($get('slug'))) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->helperText('Huruf kecil, angka, dan tanda hubung. Dipakai sebagai pengenal unik')
                            ->required()
                            ->maxLength(100)
                            ->alphaDash()
                            ->unique(Tenant::class, 'slug', ignoreRecord: true),
                        Select::make('business_type')
                            ->label('Jenis usaha')
                            ->options(BusinessType::class)
                            ->default(BusinessType::Cafe)
                            ->required(),
                        Select::make('status')
                            ->label('Status awal')
                            ->options([
                                TenantStatus::Trial->value => TenantStatus::Trial->getLabel(),
                                TenantStatus::Active->value => TenantStatus::Active->getLabel(),
                            ])
                            ->default(TenantStatus::Trial->value)
                            ->required()
                            ->visibleOn('create'),
                        DatePicker::make('subscription_ends_at')
                            ->label('Langganan berakhir')
                            ->helperText('Kosongkan bila tanpa batas. Setelah tanggal ini tenant otomatis tidak bisa memakai sistem')
                            ->native(false)
                            ->displayFormat('j M Y')
                            ->default(now()->addDays(14))
                            ->minDate(fn (string $operation) => $operation === 'create' ? today() : null),
                    ]),

                Section::make('Outlet pertama')
                    ->description('MVP: satu outlet per bisnis. Kode outlet menjadi awalan nomor order, mis. JKT01-261008-0042')
                    ->columns(3)
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('outlet_name')
                            ->label('Nama outlet')
                            ->placeholder('Kopi Senja Kemang')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('outlet_code')
                            ->label('Kode outlet')
                            ->placeholder('JKT01')
                            ->required()
                            ->maxLength(10)
                            ->regex('/^[A-Za-z0-9]+$/')
                            ->validationMessages(['regex' => 'Kode outlet hanya boleh huruf dan angka']),
                        Select::make('outlet_timezone')
                            ->label('Zona waktu')
                            ->options(self::TIMEZONES)
                            ->default(config('pos.default_timezone'))
                            ->required(),
                    ]),

                Section::make('Owner pertama')
                    ->description('Akun untuk login dashboard & aplikasi. Sampaikan kata sandi secara aman dan minta owner menggantinya')
                    ->columns(3)
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('owner_name')
                            ->label('Nama owner')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('owner_email')
                            ->label('Email owner')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // Rule tabel (bukan model Eloquent) → memeriksa email di semua tenant
                            ->unique(table: 'users', column: 'email'),
                        TextInput::make('owner_password')
                            ->label('Kata sandi awal')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rule(Password::defaults()),
                    ]),
            ]);
    }
}
