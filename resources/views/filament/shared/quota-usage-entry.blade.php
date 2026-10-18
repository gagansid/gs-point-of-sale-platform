{{-- ViewEntry infolist detail bisnis (admin): pemakaian kuota tenant (ADR 0025) --}}
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @include('filament.shared.quota-usage', ['rows' => \App\Support\Quotas::usage($getRecord())])
</x-dynamic-component>
