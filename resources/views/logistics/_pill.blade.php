@php
    $group  = $group ?? collect();
    $count  = $group->count();
    $active = $group->first(fn ($t) => ! preg_match('/complete|done|delivered/i', (string) $t->live));
    $live   = $count ? strtolower((string) (($active ?? $group->first())->live ?? '')) : '';

    if (! $count) {
        $dot = 'dot-none';
    } elseif (preg_match('/complete|done|delivered/', $live)) {
        $dot = 'dot-done';
    } elseif (str_contains($live, 'wait')) {
        $dot = 'dot-waiting';
    } elseif (str_contains($live, 'unload')) {
        $dot = 'dot-unloading';
    } elseif (str_contains($live, 'transit')) {
        $dot = 'dot-transit';
    } else {
        $dot = 'dot-idle';
    }

    // The visible pill is nothing but a tanker code, so the day, the tanker
    // and the action it performs all have to live in the accessible name.
    $dayLabel   = $date ? date('l, M j', strtotime($date)) : '';
    $countLabel = $count ? ' (' . $count . ' trip' . ($count > 1 ? 's' : '') . ')' : '';
@endphp
<button type="button" class="ts-pill {{ $tk->small ? 'is-small' : '' }}" data-open
        data-tank="{{ $tk->id }}" data-date="{{ $date }}"
        title="{{ $tk->name }}{{ $count ? ' - ' . $count . ' trip' . ($count > 1 ? 's' : '') : '' }}"
        aria-label="Open {{ $tk->code }} line-up for {{ $dayLabel }}{{ $countLabel }}">
    <span class="ts-dot {{ $dot }}"></span>{{ $tk->code }}@if ($count > 1)<small>&times;{{ $count }}</small>@endif
</button>