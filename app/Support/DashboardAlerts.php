<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SelfOrderStatus;
use App\Enums\ShiftStatus;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Filament\Dashboard\Resources\Receivables\ReceivableResource;
use App\Filament\Dashboard\Resources\SelfOrders\SelfOrderResource;
use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use App\Models\Member;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Peringatan di topbar panel /dashboard (lonceng): hal yang perlu ditindak owner/manager, dihitung langsung dari data
 * (tanpa tabel notifikasi, tanpa job). Setiap jenis hanya untuk user yang boleh membuka menu tujuannya; outlet
 * mengikuti pemilih outlet sidebar (ADR 0010). Langganan & verifikasi email sudah tampil sebagai banner; "perlu refund
 * manual" tampil di daftar Penjualan (belum ada penanda selesai, jadi bukan peringatan).
 */
final class DashboardAlerts
{
    /** Shift dianggap lupa ditutup setelah sekian jam terbuka. */
    public const STALE_SHIFT_HOURS = 24;

    /**
     * @return list<array{key: string, icon: string, color: string, title: string, description: string, url: string}>
     */
    public static function for(User $user): array
    {
        if (! $user->role->hasFullDashboard()) {
            return [];
        }

        $alerts = [];
        $outletIds = CurrentOutlet::ids();

        if (SelfOrderResource::canViewAny()) {
            $pending = SelfOrder::query()->whereIn('outlet_id', $outletIds)->where('status', SelfOrderStatus::Pending)->count();
            if ($pending > 0) {
                $alerts[] = self::alert('self_orders', 'heroicon-o-device-phone-mobile', 'warning',
                    self::count($pending).' pesanan meja menunggu kasir', 'Belum diterima atau ditolak di kasir', SelfOrderResource::getUrl());
            }
        }

        if (ProductResource::canViewAny() && Features::enabled('stock') && ($outlet = CurrentOutlet::get()) !== null) {
            $soldOut = Product::query()->where('is_active', true)->outOfStock($outlet->id)->count();
            $low = Product::query()->where('is_active', true)->lowStock($outlet->id)->count();
            if ($soldOut + $low > 0) {
                $where = Outlet::query()->count() > 1 ? ' di '.$outlet->name : '';
                $alerts[] = self::alert('stock', 'heroicon-o-archive-box', $soldOut > 0 ? 'danger' : 'warning',
                    self::count($soldOut + $low).' produk perlu restok'.$where,
                    collect([$soldOut > 0 ? self::count($soldOut).' habis' : null, $low > 0 ? self::count($low).' menipis' : null])->filter()->implode(' · '),
                    ProductResource::getUrl());
            }
        }

        if (ShiftResource::canViewAny()) {
            $stale = Shift::query()->whereIn('outlet_id', $outletIds)->where('status', ShiftStatus::Open)
                ->where('opened_at', '<=', CarbonImmutable::now()->subHours(self::STALE_SHIFT_HOURS))->count();
            if ($stale > 0) {
                $alerts[] = self::alert('shifts', 'heroicon-o-clock', 'warning',
                    self::count($stale).' shift belum ditutup', 'Terbuka lebih dari '.self::STALE_SHIFT_HOURS.' jam', ShiftResource::getUrl());
            }
        }

        if (ReceivableResource::canViewAny()) {
            $overdue = ReceivableResource::overdue(Member::query())->count();
            if ($overdue > 0) {
                $alerts[] = self::alert('receivables', 'heroicon-o-banknotes', 'warning',
                    self::count($overdue).' member kasbon lama', 'Belum lunas '.ReceivableResource::OVERDUE_DAYS.' hari atau lebih', ReceivableResource::getUrl());
            }
        }

        return $alerts;
    }

    /** @return array{key: string, icon: string, color: string, title: string, description: string, url: string} */
    private static function alert(string $key, string $icon, string $color, string $title, string $description, string $url): array
    {
        return compact('key', 'icon', 'color', 'title', 'description', 'url');
    }

    private static function count(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
