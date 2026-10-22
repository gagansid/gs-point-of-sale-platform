<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Member\PayReceivable;
use App\Enums\PaymentCategory;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Money;
use App\Support\WebCashier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Kasir web → Kasbon (ADR 0023): daftar member yang punya kasbon (cari nama/HP/kode) dan pelunasan. Tunai masuk kas
 * shift terbuka di browser ini.
 */
final class ReceivableController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(self::user()->can('member.view'), 403);
        $search = mb_substr(trim($request->string('q')->toString()), 0, 50);

        $owing = fn () => Member::query()->whereNull('anonymized_at')->where('receivable_balance', '>', 0);
        $members = $owing()->when($search !== '', fn ($q) => $q->search($search))
            ->orderByDesc('receivable_balance')->limit(50)->get();

        return view('cashier.receivables.index', [
            'members' => $members,
            'search' => $search,
            // Kartu statistik: seluruh member berkasbon (tidak ikut filter pencarian)
            'stats' => [
                'count' => $owing()->count(),
                'total' => Money::of((string) ($owing()->sum('receivable_balance') ?: '0')),
            ],
            'enabled' => MemberSetting::current()->credit_enabled,
            'methods' => PaymentMethod::query()->activeAt(CurrentOutlet::getOrFail()->id)
                ->whereNotIn('category', [PaymentCategory::Points, PaymentCategory::Online, PaymentCategory::Receivable])
                ->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request, string $member, PayReceivable $pay): RedirectResponse
    {
        abort_unless(self::user()->can('order.create') && Str::isUuid($member), 403);
        $model = Member::query()->whereNull('anonymized_at')->findOrFail($member);
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'payment_method_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'nominal', 'payment_method_id' => 'metode bayar', 'note' => 'catatan']);

        $result = $pay->handle(self::user(), $model, WebCashier::device(), (string) $data['id'], Money::of((string) $data['amount']), (string) $data['payment_method_id'], $data['note'] ?? null);

        return redirect()->route('cashier.receivables')
            ->with('status', $result['replayed'] ? 'Pelunasan sudah tercatat' : 'Pelunasan '.$model->name.' '.Money::format(Money::sub('0.00', $result['entry']->amount)).' dicatat');
    }

    private static function user(): User
    {
        return WebCashier::user() ?? abort(401);
    }
}
