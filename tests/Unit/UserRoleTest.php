<?php

declare(strict_types=1);

use App\Enums\UserRole;

/**
 * Membaca matriks permission dari docs/SPEC.md: permission => [owner, manager, supervisor, cashier].
 * ✅ = boleh langsung; ❌ dan 🔑 (butuh PIN approver) = tidak boleh langsung.
 *
 * @return array<string, list<bool>>
 */
function specPermissionMatrix(): array
{
    preg_match_all(
        '/^\| `([a-z_]+\.[a-z_]+)` \| [^|]+ \| (\S+) \| (\S+) \| (\S+) \| (\S+) \|$/mu',
        file_get_contents(base_path('docs/SPEC.md')),
        $rows,
        PREG_SET_ORDER,
    );

    $matrix = [];
    foreach ($rows as $row) {
        $matrix[$row[1]] = array_map(fn (string $cell): bool => $cell === '✅', array_slice($row, 2, 4));
    }

    return $matrix;
}

it('daftar permission sama dengan tabel di SPEC', function () {
    $spec = array_keys(specPermissionMatrix());

    expect($spec)->toHaveCount(19)
        ->and(UserRole::PERMISSIONS)->toEqualCanonicalizing($spec);
});

it('setiap role mengikuti matriks permission di SPEC', function () {
    $roles = [UserRole::Owner, UserRole::Manager, UserRole::Supervisor, UserRole::Cashier];

    foreach (specPermissionMatrix() as $permission => $expected) {
        foreach ($roles as $i => $role) {
            expect($role->allows($permission))->toBe(
                $expected[$i],
                "{$role->value} → {$permission}",
            );
        }
    }
});

it('wildcard tidak mengizinkan ability yang tidak dikenal', function (UserRole $role, string $ability) {
    expect($role->allows($ability))->toBeFalse();
})->with([
    [UserRole::Owner, 'admin.tenant.delete'],
    [UserRole::Owner, 'update'],
    [UserRole::Manager, 'order.hack'],
    [UserRole::Supervisor, 'order'],
]);

it('punya label Indonesia dan warna badge', function () {
    expect(UserRole::Cashier->getLabel())->toBe('Kasir')
        ->and(UserRole::Owner->getColor())->toBe('primary');
});
