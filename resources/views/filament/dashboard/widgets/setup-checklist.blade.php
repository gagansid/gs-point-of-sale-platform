{{-- Beranda: checklist "Mulai berjualan" (SetupChecklistWidget). --}}
@php
    $steps = $this->getSteps();
    $done = collect($steps)->where('done', true)->count();
    $total = count($steps);
@endphp

<x-filament-widgets::widget>
    <section class="gs-setup">
        <header class="gs-setup-head">
            <div>
                <h2 class="gs-setup-title">Mulai berjualan</h2>
                <p class="gs-setup-sub">{{ $done }} dari {{ $total }} langkah selesai. Panduan ini hilang otomatis setelah semuanya selesai.</p>
            </div>
            <div class="gs-setup-head-actions">
                {{ $this->applyTemplateAction }}
            </div>
        </header>

        <div class="gs-setup-progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $done }}">
            <span style="width: {{ $total > 0 ? round($done / $total * 100) : 0 }}%"></span>
        </div>

        <ol class="gs-setup-steps">
            @foreach ($steps as $index => $step)
                <li @class(['gs-setup-step', 'is-done' => $step['done']])>
                    <span class="gs-setup-mark" aria-hidden="true">
                        @if ($step['done'])
                            <x-filament::icon icon="heroicon-o-check" />
                        @else
                            {{ $index + 1 }}
                        @endif
                    </span>
                    <div class="gs-setup-text">
                        <strong>{{ $step['label'] }}</strong>
                        <span>{{ $step['hint'] }}</span>
                    </div>
                    @if (! $step['done'] && $step['url'])
                        <a href="{{ $step['url'] }}" class="gs-setup-link">Mulai</a>
                    @elseif ($step['done'])
                        <span class="gs-setup-status">Selesai</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
