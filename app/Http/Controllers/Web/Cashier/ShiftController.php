<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Shift\CloseShift;
use App\Actions\Shift\OpenShift;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shift kasir web (Q54): buka (kas awal), rekap berjalan (X-report), tutup (Z-report).
 * Shift terikat device browser, sama seperti tablet (OpenShift/CloseShift).
 */
final class ShiftController extends Controller
{
    public function show(Request $request, ShiftRecap $recap): View
    {
        $shift = self::openShift($request);

        return view('cashier.shift.show', [
            'shift' => $shift,
            'recap' => $shift !== null ? $recap->for($shift) : null,
            'timezone' => CurrentOutlet::timezone(),
            'user' => self::user(),
            // Shift dibuka orang lain di browser ini → hanya bisa tutup paksa
            'mustForce' => $shift !== null && $shift->opened_by !== self::user()->id,
        ]);
    }

    public function store(Request $request, OpenShift $open): RedirectResponse
    {
        abort_unless(self::user()->can('shift.operate'), 403);
        $data = $request->validate(['opening_cash' => self::moneyRule()], [], ['opening_cash' => 'kas awal']);

        $result = $open->handle(self::user(), WebCashier::device(), Money::of((string) $data['opening_cash']));

        return redirect()->route('cashier.home')
            ->with('status', $result['replayed'] ? 'Shift sudah terbuka' : 'Shift berhasil dibuka');
    }

    public function close(Request $request, CloseShift $close): RedirectResponse
    {
        $shift = self::openShift($request) ?? throw ValidationException::withMessages(['cashier' => 'Tidak ada shift terbuka di browser ini.']);
        $force = $shift->opened_by !== self::user()->id;
        abort_unless(self::user()->can($force ? 'shift.force_close' : 'shift.operate'), 403);

        $data = $request->validate([
            'actual_cash' => self::moneyRule(),
            'note' => [$force ? 'required' : 'nullable', 'string', 'max:255'],
        ], [], ['actual_cash' => 'kas aktual', 'note' => 'catatan']);

        $result = $close->handle(self::user(), $shift, Money::of((string) $data['actual_cash']), $data['note'] ?? null, $force);

        return redirect()->route('cashier.shifts.show', $result['shift'])->with('status', 'Shift berhasil ditutup');
    }

    /** Rekap shift mana pun yang boleh dilihat (shift sendiri, atau view_all / view_shift). */
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
