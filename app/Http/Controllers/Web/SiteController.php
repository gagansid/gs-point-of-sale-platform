<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Sales\CreateSalesLead;
use App\Actions\Sales\Data\SalesLeadData;
use App\Enums\BusinessType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ContactSalesRequest;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Quota;
use App\Support\Domains;
use App\Support\Quotas;
use App\Support\SystemSettings;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Halaman depan gspos.id: penjualan sistem, harga paket publik (ADR 0025), dan form "Hubungi sales".
 */
final class SiteController extends Controller
{
    public function home(): View
    {
        $settings = app(SystemSettings::class);

        return view('site.home', [
            'loginUrl' => Domains::enabled() ? route('login') : Filament::getPanel('dashboard')->getLoginUrl(),
            'businessTypes' => BusinessType::cases(),
            'signupEnabled' => $settings->signupEnabled(),
            'trialDays' => $settings->trialDays(),
            'trialPlanId' => $settings->trialPlanId(),
            // Sama dengan pilihan di halaman Langganan owner: aktif, publik, dan di dalam periode (promo)
            'plans' => Plan::query()->where('is_active', true)->where('is_public', true)->withinPeriod()
                ->orderBy('sort_order')->orderBy('price_monthly')->get(),
            'features' => Feature::options(),
            'quotas' => Quota::options(),
            // Kuota yang hanya berarti bila fiturnya ada di paket (tanpa program member, "Member aktif" tidak relevan)
            'quotaFeatures' => [Quotas::MEMBERS => 'member'],
        ]);
    }

    public function contact(ContactSalesRequest $request, CreateSalesLead $action): RedirectResponse
    {
        // Bot yang mengisi honeypot mendapat respons sukses yang sama, tetapi tidak disimpan
        if (! $request->isBot()) {
            $action->handle(SalesLeadData::fromArray($request->validated()), $request->ip());
        }

        return redirect()->to(route('landing').'#contact')
            ->with('contact_sent', true);
    }
}
