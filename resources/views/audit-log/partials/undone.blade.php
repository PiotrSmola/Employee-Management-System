{{-- Under an audit entry that was undone later (the entry itself is never changed) --}}
@if ($undo)
    <div class="small text-warning-emphasis mt-1">
        <i class="bi bi-arrow-counterclockwise me-1"></i>
        {{ __('Undone on :date by :user (entry #:id)', [
            'date' => $undo->created_at->format('d.m.Y H:i'),
            'user' => $undo->user?->name ?? __('System'),
            'id' => $undo->id,
        ]) }}
    </div>
@endif
