<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Actions\User\SetEmployeeActive;
use App\Actions\User\UnlockEmployeePin;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\EmployeeRequest;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Models\User;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

#[Group('Setelan')]
final class UserController extends Controller
{
    /**
     * Daftar karyawan.
     *
     * Permission user.manage (owner). Filter: search (nama/email), role, is_active.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        $users = User::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when(filled($validated['role'] ?? null), fn (Builder $q) => $q->where('role', $validated['role']))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min((int) ($validated['per_page'] ?? config('pos.pagination.per_page')), (int) config('pos.pagination.max_per_page')));

        return ApiResponse::paginated($users, EmployeeResource::class);
    }

    /**
     * Detail karyawan.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return ApiResponse::success(EmployeeResource::make($user)->resolve($request));
    }

    /**
     * Tambah karyawan.
     *
     * Owner/manager: email + password wajib. Supervisor/kasir: pin 6 digit wajib (login tablet).
     * `id` opsional sebagai idempotency key.
     */
    public function store(EmployeeRequest $request, SaveEmployee $action): JsonResponse
    {
        $result = $action->handle(null, EmployeeData::fromArray($request->validated()));

        return ApiResponse::success(
            EmployeeResource::make($result['user'])->resolve($request),
            'Karyawan berhasil ditambahkan',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Ubah karyawan.
     *
     * Field yang tidak dikirim tetap. Ganti pin/password/role atau nonaktifkan → semua sesi karyawan
     * itu diputus. Owner aktif terakhir tidak bisa diturunkan/dinonaktifkan (409 LAST_OWNER_REQUIRED).
     */
    public function update(EmployeeRequest $request, User $user, SaveEmployee $save, SetEmployeeActive $setActive): JsonResponse
    {
        Gate::authorize('update', $user);

        $data = EmployeeData::fromArray([
            'name' => $user->name,
            'role' => $user->role,
            'email' => $user->email,
            ...$request->safe()->except(['is_active']),
        ]);

        $actor = ApiActor::user($request);

        $updated = DB::transaction(function () use ($user, $data, $request, $save, $setActive, $actor): User {
            $updated = $save->handle($user, $data)['user'];

            if ($request->has('is_active') && $request->boolean('is_active') !== $updated->is_active) {
                $updated = $setActive->handle($updated, $request->boolean('is_active'), $actor);
            }

            return $updated;
        });

        return ApiResponse::success(EmployeeResource::make($updated)->resolve($request), 'Karyawan berhasil diperbarui');
    }

    /**
     * Buka kunci PIN.
     *
     * PIN terkunci 15 menit setelah 5 kali salah; owner bisa membukanya lebih cepat.
     */
    public function unlockPin(Request $request, User $user, UnlockEmployeePin $action): JsonResponse
    {
        Gate::authorize('update', $user);

        return ApiResponse::success(EmployeeResource::make($action->handle($user))->resolve($request), 'Kunci PIN dibuka');
    }
}
