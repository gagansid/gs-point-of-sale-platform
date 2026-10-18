<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MemberSetting;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;

/**
 * Jenis laporan (tab halaman Laporan, sheet Excel, bagian PDF). Satu sumber untuk label, ikon,
 * dan izin (Q49): tab operasional boleh untuk report.view_shift (supervisor, maks. 31 hari),
 * sisanya hanya report.view.
 */
enum ReportTab: string
{
    case Summary = 'summary';
    case Products = 'products';
    case Categories = 'categories';
    case Profit = 'profit';
    case Options = 'options';
    case PaymentMethods = 'payment_methods';
    case Employees = 'employees';
    case Shifts = 'shifts';
    case Time = 'time';
    case OrderTypes = 'order_types';
    case Outlets = 'outlets';
    case Discounts = 'discounts';
    case Stock = 'stock';
    case Members = 'members';

    public function label(): string
    {
        return match ($this) {
            self::Summary => 'Ringkasan',
            self::Products => 'Produk',
            self::Categories => 'Kategori',
            self::Profit => 'Laba kotor',
            self::Options => 'Opsi',
            self::PaymentMethods => 'Metode bayar',
            self::Employees => 'Karyawan',
            self::Shifts => 'Shift',
            self::Time => 'Waktu',
            self::OrderTypes => 'Tipe order',
            self::Outlets => 'Outlet',
            self::Discounts => 'Diskon & void',
            self::Stock => 'Stok',
            self::Members => 'Member',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Summary => 'heroicon-o-chart-bar',
            self::Products => 'heroicon-o-cube',
            self::Categories => 'heroicon-o-tag',
            self::Profit => 'heroicon-o-scale',
            self::Options => 'heroicon-o-adjustments-horizontal',
            self::PaymentMethods => 'heroicon-o-credit-card',
            self::Employees => 'heroicon-o-users',
            self::Shifts => 'heroicon-o-clock',
            self::Time => 'heroicon-o-calendar-days',
            self::OrderTypes => 'heroicon-o-shopping-bag',
            self::Outlets => 'heroicon-o-building-storefront',
            self::Discounts => 'heroicon-o-shield-check',
            self::Stock => 'heroicon-o-archive-box',
            self::Members => 'heroicon-o-user-group',
        };
    }

    /**
     * Fitur paket yang dibutuhkan tab (ADR 0025): null = laporan dasar, selalu ada. Diskon & void tetap dasar karena
     * kontrol kecurangan tidak boleh tergantung paket.
     */
    public function feature(): ?string
    {
        return match ($this) {
            self::Profit, self::Options, self::Employees, self::Time, self::Outlets => 'advanced_reports',
            self::Members => 'member',
            default => null,
        };
    }

    /** Tab operasional (report.view_shift), Q49. */
    public function isOperational(): bool
    {
        return in_array($this, [self::Summary, self::Products, self::PaymentMethods, self::Employees, self::Shifts, self::Discounts], true);
    }

    /** @return list<string> salah satu cukup */
    public function permissions(): array
    {
        return $this->isOperational() ? ['report.view', 'report.view_shift'] : ['report.view'];
    }

    /**
     * Boleh dilihat user di konteks outlet saat ini. Tab Outlet hanya saat "Semua outlet" dan > 1 outlet;
     * tab tidak relevan disembunyikan, bukan ditampilkan lalu ditolak.
     */
    public function visibleFor(?User $user): bool
    {
        if ($user === null || ! collect($this->permissions())->contains(fn (string $p): bool => $user->can($p))) {
            return false;
        }

        if ($this->feature() !== null && ! Features::enabled($this->feature())) {
            return false;
        }

        if ($this === self::Outlets) {
            return CurrentOutlet::selectedId() === null && count($user->outletIds()) > 1;
        }

        // Program member (ADR 0013) belum pernah diaktifkan: tab tidak relevan
        if ($this === self::Members) {
            return MemberSetting::query()->exists();
        }

        return true;
    }

    /** @return list<self> */
    public static function visibleTabs(?User $user): array
    {
        return array_values(array_filter(self::cases(), fn (self $tab): bool => $tab->visibleFor($user)));
    }
}
