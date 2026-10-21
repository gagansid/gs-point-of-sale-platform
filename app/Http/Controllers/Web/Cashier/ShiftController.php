<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Auth\PinApprovers;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Shift\CloseShift;
use App\Actions\Shift\CorrectOpeningCash;
use App\Actions\Shift\Data\CashMovementData;
use App\Actions\Shift\OpenShift;
use App\Actions\Shift\RecordCashMovement;
use App\Enums\CashMovementReason;
use App\Enums\CashMovementType;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shift\ApprovalRules;
use App\Http\Requests\Api\V1\Shift\CashMovementRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\Report\ReportExporter;
use App\Services\Report\ShiftRecap;
use App\Support\CurrentOutlet;
use App\Support\ExportThrottle;
use App\Support\Money;
use App\Support\WebCashier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shift kasir web (Q54, ADR 0017): buka (carry-over kas awal), rekap berjalan (X-report), kas masuk/keluar,
 * koreksi kas awal, tutup (Z-report) oleh siapa saja yang bertugas di browser ini. Shift terikat device
 * browser, sama seperti tablet (OpenShift/CloseShift).
 */
final class ShiftController extends Controller
{
    public function show(Request $request, ShiftRecap $recap): View
    {
        $shift = self::openShift($request);
        $outlet = CurrentOutlet::getOrFail();
        $user = self::user();

        return view('cashier.shift.show', [
            'shift' => $shift,
            'recap' => $shift !== null ? $recap->for($shift) : null,
            'timezone' => CurrentOutlet::timezone(),
            'user' => $user,
            'outlet' => $outlet,
            'carryOver' => $shift === null ? OpenShift::carryOverFor(WebCashier::device()) : null,
            'canEditOpening' => $user->can('shift.edit_opening_cash'),
            'openingApprovers' => PinApprovers::for($user, 'shift.edit_opening_cash', $outlet->id),
            'cashOutApprovers' => PinApprovers::for($user, 'shift.cash_out_over_limit', $outlet->id),
            'movementId' => self::newMovementId(),
            'reasons' => [
                CashMovementType::In->value => CashMovementReason::for(CashMovementType::In),
                CashMovementType::Out->value => CashMovementReason::for(CashMovementType::Out),
            ],
        ]);
    }

    public function store(Request $request, OpenShift $open): RedirectResponse
    {
        abort_unless(self::user()->can('shift.operate'), 403);
        $data = $request->validate([
            // Kosong = carry-over sisa laci shift sebelumnya (ADR 0017)
            'opening_cash' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'opening_cash_note' => ['nullable', 'string', 'max:255'],
            ...ApprovalRules::rules(),
        ], [], ['opening_cash' => 'kas awal', 'opening_cash_note' => 'alasan', ...ApprovalRules::attributes()]);

        // Shift pertama di browser ini: pesan di bawah field, bukan toast
        if (blank($data['opening_cash'] ?? null) && OpenShift::carryOverFor(WebCashier::device()) === null) {
            throw ValidationException::withMessages(['opening_cash' => 'Kas awal wajib diisi.']);
        }

        $result = $open->handle(
            self::user(),
            WebCashier::device(),
            filled($data['opening_cash'] ?? null) ? Money::of((string) $data['opening_cash']) : null,
            $data['opening_cash_note'] ?? null,
            CheckoutData::parseApproval($data),
        );

        return redirect()->route('cashier.home')
            ->with('status', $result['replayed'] ? 'Shift sudah terbuka' : 'Shift berhasil dibuka');
    }

    /** Tutup shift browser ini: siapa saja yang sedang bertugas (ADR 0017), tanpa tutup paksa. */
    public function close(Request $request, CloseShift $close): RedirectResponse
    {
        $shift = self::openShift($request) ?? throw ValidationException::withMessages(['cashier' => 'Tidak ada shift terbuka di browser ini.']);
        abort_unless(self::user()->can('shift.operate'), 403);

        $data = $request->validate([
            'actual_cash' => self::moneyRule(),
            'cash_deposited' => ['nullable', 'decimal:0,2', 'min:0', 'lte:actual_cash', 'max:9999999999999.99'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['actual_cash' => 'kas dihitung', 'cash_deposited' => 'disetor', 'note' => 'catatan']);

        $result = $close->handle(
            self::user(),
            $shift,
            WebCashier::device(),
            Money::of((string) $data['actual_cash']),
            filled($data['cash_deposited'] ?? null) ? Money::of((string) $data['cash_deposited']) : null,
            $data['note'] ?? null,
        );

        return redirect()->route('cashier.shifts.show', $result['shift'])->with('status', 'Shift berhasil ditutup');
    }

    /** Koreksi kas awal shift berjalan (izin shift.edit_opening_cash atau PIN approver). */
    public function correctOpeningCash(Request $request, CorrectOpeningCash $correct): RedirectResponse
    {
        $shift = self::openShift($request) ?? throw ValidationException::withMessages(['cashier' => 'Tidak ada shift terbuka di browser ini.']);
        abort_unless(self::user()->can('shift.operate'), 403);

        $data = $request->validate([
            'opening_cash' => self::moneyRule(),
            'opening_cash_note' => ['required', 'string', 'max:255'],
            ...ApprovalRules::rules(),
        ], [], ['opening_cash' => 'kas awal', 'opening_cash_note' => 'alasan', ...ApprovalRules::attributes()]);

        $correct->handle(self::user(), $shift, WebCashier::device(), Money::of((string) $data['opening_cash']), (string) $data['opening_cash_note'], CheckoutData::parseApproval($data));

        return redirect()->route('cashier.shift')->with('status', 'Kas awal berhasil dikoreksi');
    }

    /** Kas masuk/keluar di shift browser ini; ID dibuat server per kiriman form (token form sekali pakai). */
    public function storeCashMovement(Request $request, RecordCashMovement $record): RedirectResponse
    {
        abort_unless(self::user()->can('shift.operate'), 403);
        $data = $request->validate(
            ['id' => ['required', 'uuid'], ...CashMovementRequest::fieldRules()],
            CashMovementRequest::fieldMessages(),
            CashMovementRequest::fieldAttributes(),
        );

        $result = $record->handle(self::user(), WebCashier::device(), CashMovementData::fromArray($data), CheckoutData::parseApproval($data));
        $type = $result['movement']->type;

        return redirect()->route('cashier.shift')
            ->with('status', $result['replayed'] ? 'Kas sudah tercatat' : $type->getLabel().' '.Money::format($result['movement']->amount).' dicatat');
    }

    /** Rekap shift mana pun yang boleh dilihat (shift yang pernah dipegang, atau view_all / view_shift). */
    public function recap(Shift $shift, ShiftRecap $recap): View
    {
        abort_unless(ShiftRecap::canView(self::user(), $shift), 403);

        return view('cashier.shift.recap', ['recap' => $recap->for($shift), 'timezone' => CurrentOutlet::timezone()]);
    }

    public function pdf(Shift $shift, ShiftRecap $recap, ReportExporter $exporter): Response
    {
        abort_unless(ShiftRecap::canView(self::user(), $shift), 403);

        if (($wait = ExportThrottle::attempt(self::user())) !== null) {
            throw BusinessException::of(ErrorCode::TooManyRequests, ExportThrottle::message($wait));
        }

        return $exporter->shiftPdf(self::user(), $shift, $recap);
    }

    public static function openShift(Request $request): ?Shift
    {
        return Shift::query()->with('openedBy')->where('open_device_key', WebCashier::device()->id)->first();
    }

    /** ID kas masuk/keluar untuk form berikutnya (idempotency key: klik ganda tidak mencatat dua kali). */
    public static function newMovementId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return list<string> */
    private static function moneyRule(): array
    {
        return ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'];
    }

    private static function user(): User
    {
        return WebCashier::user() ?? abort(401);
    }
}
