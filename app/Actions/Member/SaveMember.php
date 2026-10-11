<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Actions\Member\Data\MemberData;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\Outlet;
use App\Support\Idempotency;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * Daftar (member = null) atau ubah member (ADR 0013, Q61). Nomor HP disimpan +62…, unik per bisnis; mendaftar wajib
 * persetujuan data. Daftar idempotent lewat id dari klien. Kode member 6 karakter acak tanpa huruf/angka mirip.
 */
final class SaveMember
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return array{member: Member, replayed: bool}
     *
     * @throws BusinessException VALIDATION_ERROR (phone, consent, birth_day)
     */
    public function handle(?Member $member, MemberData $data, ?Outlet $joinedAt = null): array
    {
        if ($member === null && ($existing = Idempotency::existing(Member::class, $data->id)) !== null) {
            return ['member' => $existing, 'replayed' => true];
        }

        $phone = Phone::normalize($data->phone)
            ?? throw self::invalid('phone', 'Nomor HP tidak valid. Contoh: 0812 3456 7890');

        if ($member === null && ! $data->consent) {
            throw self::invalid('consent', 'Pelanggan harus menyetujui pemakaian data untuk program member');
        }

        if ($data->birthDay !== null && ($data->birthMonth === null || ! checkdate($data->birthMonth, $data->birthDay, 2000))) {
            throw self::invalid('birth_day', 'Tanggal lahir tidak valid');
        }

        $member = DB::transaction(function () use ($member, $data, $phone, $joinedAt): Member {
            $taken = Member::query()->where('phone', $phone)->when($member !== null, fn ($q) => $q->whereKeyNot($member->id))->lockForUpdate()->exists();

            if ($taken) {
                throw self::invalid('phone', 'Nomor HP sudah terdaftar sebagai member');
            }

            $isNew = $member === null;
            $member ??= new Member;

            if ($isNew) {
                if ($data->id !== null) {
                    $member->id = $data->id;
                }
                $member->forceFill(['code' => self::newCode(), 'consent_at' => now(), 'joined_outlet_id' => $joinedAt?->id]);
            }

            $member->fill([
                'name' => $data->name,
                'phone' => $phone,
                'email' => $data->email,
                'birth_day' => $data->birthDay,
                'birth_month' => $data->birthDay !== null ? $data->birthMonth : null,
                'notes' => $data->notes,
            ])->save();

            return $member;
        });

        return ['member' => $member, 'replayed' => false];
    }

    private static function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Member::query()->where('code', $code)->exists());

        return $code;
    }

    private static function invalid(string $field, string $message): BusinessException
    {
        return BusinessException::of(ErrorCode::ValidationError, $message, [$field => [$message]]);
    }
}
