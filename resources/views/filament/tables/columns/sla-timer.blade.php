@php
    $snapshot = app(\App\Services\Sla\SlaCalculationService::class)->mostUrgent($getRecord());
@endphp

<div class="px-3 py-2">
    @if ($snapshot)
        <x-hd.sla-badge :snapshot="$snapshot" :live="true" />
    @else
        <span class="text-xs text-gray-500">—</span>
    @endif
</div>
