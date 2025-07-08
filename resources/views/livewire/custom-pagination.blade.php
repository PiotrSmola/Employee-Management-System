@if ($paginator->hasPages())
    <nav aria-label="Pagination" wire:loading.class="opacity-50">
        <ul class="pagination pagination-sm mb-0">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <li class="page-item disabled">
                    <span class="page-link">‹</span>
                </li>
            @else
                <li class="page-item">
                    <button type="button" class="page-link" wire:click="previousPage" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="previousPage">‹</span>
                        <span wire:loading wire:target="previousPage" class="spinner-border spinner-border-sm"></span>
                    </button>
                </li>
            @endif

            {{-- First Page --}}
            @if ($paginator->currentPage() > 3)
                <li class="page-item">
                    <button type="button" class="page-link" wire:click="gotoPage(1)" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="gotoPage(1)">1</span>
                        <span wire:loading wire:target="gotoPage(1)" class="spinner-border spinner-border-sm"></span>
                    </button>
                </li>
                @if ($paginator->currentPage() > 4)
                    <li class="page-item disabled">
                        <span class="page-link">...</span>
                    </li>
                @endif
            @endif

            {{-- Pages around current --}}
            @for ($page = max(1, $paginator->currentPage() - 1); $page <= min($paginator->lastPage(), $paginator->currentPage() + 1); $page++)
                @if ($page == $paginator->currentPage())
                    <li class="page-item active">
                        <span class="page-link">{{ $page }}</span>
                    </li>
                @else
                    <li class="page-item">
                        <button type="button" class="page-link" wire:click="gotoPage({{ $page }})"
                            wire:loading.attr="disabled">
                            <span wire:loading.remove
                                wire:target="gotoPage({{ $page }})">{{ $page }}</span>
                            <span wire:loading wire:target="gotoPage({{ $page }})"
                                class="spinner-border spinner-border-sm"></span>
                        </button>
                    </li>
                @endif
            @endfor

            {{-- Last Page --}}
            @if ($paginator->currentPage() < $paginator->lastPage() - 2)
                @if ($paginator->currentPage() < $paginator->lastPage() - 3)
                    <li class="page-item disabled">
                        <span class="page-link">...</span>
                    </li>
                @endif
                <li class="page-item">
                    <button type="button" class="page-link" wire:click="gotoPage({{ $paginator->lastPage() }})"
                        wire:loading.attr="disabled">
                        <span wire:loading.remove
                            wire:target="gotoPage({{ $paginator->lastPage() }})">{{ $paginator->lastPage() }}</span>
                        <span wire:loading wire:target="gotoPage({{ $paginator->lastPage() }})"
                            class="spinner-border spinner-border-sm"></span>
                    </button>
                </li>
            @endif

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <button type="button" class="page-link" wire:click="nextPage" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="nextPage">›</span>
                        <span wire:loading wire:target="nextPage" class="spinner-border spinner-border-sm"></span>
                    </button>
                </li>
            @else
                <li class="page-item disabled">
                    <span class="page-link">›</span>
                </li>
            @endif
        </ul>
    </nav>
@endif
