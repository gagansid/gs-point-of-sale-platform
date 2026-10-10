<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kode error resmi API — cermin tabel "Daftar error.code resmi" di docs/SPEC.md.
 *
 * Menambah case wajib didahului perubahan SPEC (docs/standards/api/error-codes.md §4).
 * Test ErrorCodeTest memastikan enum dan SPEC selalu sama.
 */
enum ErrorCode: string
{
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidPin = 'INVALID_PIN';
    case Forbidden = 'FORBIDDEN';
    case DeviceNotRegistered = 'DEVICE_NOT_REGISTERED';
    case TenantSuspended = 'TENANT_SUSPENDED';
    case SubscriptionExpired = 'SUBSCRIPTION_EXPIRED';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case ApprovalRequired = 'APPROVAL_REQUIRED';
    case SelfApprovalNotAllowed = 'SELF_APPROVAL_NOT_ALLOWED';
    case NotFound = 'NOT_FOUND';
    case ShiftNotOpen = 'SHIFT_NOT_OPEN';
    case OrderAlreadyClosed = 'ORDER_ALREADY_CLOSED';
    case LastOwnerRequired = 'LAST_OWNER_REQUIRED';
    case OutletLimitReached = 'OUTLET_LIMIT_REACHED';
    case ValidationError = 'VALIDATION_ERROR';
    case PaymentExceedsBalance = 'PAYMENT_EXCEEDS_BALANCE';
    case DiscountOverLimit = 'DISCOUNT_OVER_LIMIT';
    case PinLocked = 'PIN_LOCKED';
    case AppUpdateRequired = 'APP_UPDATE_REQUIRED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case ServerError = 'SERVER_ERROR';
    case Maintenance = 'MAINTENANCE';

    /** Status HTTP baku untuk kode ini. */
    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated, self::InvalidPin => 401,
            self::Forbidden, self::DeviceNotRegistered, self::TenantSuspended,
            self::SubscriptionExpired, self::EmailNotVerified,
            self::ApprovalRequired, self::SelfApprovalNotAllowed => 403,
            self::NotFound => 404,
            self::ShiftNotOpen, self::OrderAlreadyClosed, self::LastOwnerRequired, self::OutletLimitReached => 409,
            self::ValidationError, self::PaymentExceedsBalance, self::DiscountOverLimit => 422,
            self::PinLocked => 423,
            self::AppUpdateRequired => 426,
            self::TooManyRequests => 429,
            self::ServerError => 500,
            self::Maintenance => 503,
        };
    }

    /** Pesan default (Bahasa Indonesia) bila pemanggil tidak memberi pesan spesifik. */
    public function message(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Sesi berakhir, silakan login ulang',
            self::InvalidPin => 'PIN salah',
            self::Forbidden => 'Anda tidak memiliki akses',
            self::DeviceNotRegistered => 'Perangkat belum terdaftar atau aksesnya sudah dicabut',
            self::TenantSuspended => 'Bisnis ditangguhkan, hubungi tim gs.POS',
            self::SubscriptionExpired => 'Masa trial/langganan berakhir. Data hanya bisa dilihat; hubungi tim gs.POS untuk berlangganan',
            self::EmailNotVerified => 'Verifikasi email owner terlebih dahulu sebelum bertransaksi',
            self::ApprovalRequired => 'Aksi ini membutuhkan PIN atasan',
            self::SelfApprovalNotAllowed => 'Approval tidak boleh oleh diri sendiri',
            self::NotFound => 'Data tidak ditemukan',
            self::ShiftNotOpen => 'Shift belum dibuka',
            self::OrderAlreadyClosed => 'Transaksi sudah selesai atau dibatalkan',
            self::LastOwnerRequired => 'Bisnis wajib memiliki minimal satu owner aktif',
            self::OutletLimitReached => 'Jumlah outlet aktif sudah mencapai batas paket. Hubungi tim gs.POS untuk menambah outlet',
            self::ValidationError => 'Data tidak valid',
            self::PaymentExceedsBalance => 'Pembayaran melebihi sisa tagihan',
            self::DiscountOverLimit => 'Diskon melebihi batas yang diizinkan',
            self::PinLocked => 'PIN terkunci sementara karena terlalu banyak percobaan',
            self::AppUpdateRequired => 'Versi aplikasi terlalu lama, silakan perbarui',
            self::TooManyRequests => 'Terlalu banyak percobaan, coba lagi nanti',
            self::ServerError => 'Terjadi kesalahan pada server',
            self::Maintenance => 'Sistem sedang dalam perbaikan',
        };
    }
}
