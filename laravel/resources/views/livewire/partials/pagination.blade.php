{{--
    Listing pagination (design spec §5 "Pagination", Paper board 2J3-0 "Pagination"):
    "‹ Edellinen", page numbers in ink 800, the current page "Sivu N" in fox-deep with a wavy
    underline, "Seuraava ›". Link URLs and rel="prev"/"next" are unchanged (Pagination SEO,
    root AGENTS.md). On a phone only the edges and the current page show.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Sivutus" class="vk-pager">
        @if ($paginator->onFirstPage())
            <span class="vk-pager-edge" aria-disabled="true">‹ Edellinen</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="vk-pager-edge vk-focus" aria-label="Edellinen">‹ Edellinen</a>
        @endif

        @foreach ($elements as $element)
            {{-- "Three Dots" Separator --}}
            @if (is_string($element))
                <span class="vk-pager-page vk-pager-dots hidden sm:inline" aria-disabled="true">{{ $element }}</span>
            @endif

            {{-- Array Of Links --}}
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="vk-pager-current" aria-current="page">
                            <span>Sivu {{ $page }}</span>
                            <x-ink.wave width="60" stroke="3" />
                        </span>
                    @else
                        <a href="{{ $url }}" class="vk-pager-page vk-focus hidden sm:inline" aria-label="Sivu {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="vk-pager-edge vk-focus" aria-label="Seuraava">Seuraava ›</a>
        @else
            <span class="vk-pager-edge" aria-disabled="true">Seuraava ›</span>
        @endif
    </nav>
@endif
