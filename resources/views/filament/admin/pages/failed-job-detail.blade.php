{{-- Detail job gagal: error lengkap (di-escape). --}}
<div class="space-y-3 text-sm">
    <p><span class="text-gray-500">UUID:</span> <code>{{ $job->uuid }}</code> · <span class="text-gray-500">Antrean:</span> {{ $job->queue }}</p>
    <pre class="max-h-[60vh] overflow-auto rounded-lg bg-gray-950 p-4 text-xs leading-relaxed text-gray-100 whitespace-pre-wrap">{{ $job->exception }}</pre>
</div>
