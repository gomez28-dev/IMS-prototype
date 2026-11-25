{{-- The corrections made to this ATL, wherever the record is listed.

     The history belongs to the ATL, not to the approval queue that first
     showed it: approving a reopened ATL does not clear it, so every list that
     shows the record still says it was corrected, by whom, and what moved. --}}
@if ($atl->corrections->isEmpty())
    <span class="text-muted">—</span>
@else
    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill">
        Corrected{{ $atl->corrections->count() > 1 ? ' '.$atl->corrections->count().'×' : '' }}
    </span>
    <details class="mt-1">
        <summary class="text-primary" style="cursor: pointer;">What changed</summary>
        @foreach ($atl->corrections as $correction)
            <div class="small text-muted mt-1">
                {{ $correction->admin->name ?? 'Unknown' }} · {{ $correction->created_at?->format('M d, Y g:i A') }}
            </div>
            <table class="table table-sm mb-0 mt-1">
                <thead>
                    <tr>
                        <th class="small fw-semibold">Field</th>
                        <th class="small fw-semibold">Before</th>
                        <th class="small fw-semibold">After</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($correction->changesForDisplay() as $change)
                        <tr>
                            <td class="small">{{ $change['label'] }}</td>
                            <td class="small text-muted">{{ $change['before'] }}</td>
                            <td class="small fw-semibold">{{ $change['after'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    </details>
@endif
