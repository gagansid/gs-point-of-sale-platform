<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Sales\CreateSalesLead;
use App\Actions\Sales\Data\SalesLeadData;
use App\Enums\BusinessType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ContactSalesRequest;
use App\Support\Domains;
use App\Support\SystemSettings;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Halaman depan gspos.id: penjualan sistem + form "Hubungi sales".
 */
final class SiteController extends Controller
{
    public function home(): View
    {
        return view('site.home', [
            'loginUrl' => Domains::enabled() ? route('login') : Filament::getPanel('dashboard')->getLoginUrl(),
            'businessTypes' => BusinessType::cases(),
            'signupEnabled' => app(SystemSettings::class)->signupEnabled(),
            'trialDays' => app(SystemSettings::class)->trialDays(),
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
