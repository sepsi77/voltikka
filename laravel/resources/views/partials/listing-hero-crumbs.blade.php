{{-- Listing breadcrumbs in the hero, above the H1 (Paper "R4 · calm top"): one band less
     between the Preferred Sources strip and the controls. Colours follow the hero tone. --}}
@if ($hasSeoFilter)
    <nav class="vk-crumbs vk-crumbs--hero" aria-label="Breadcrumb">
        <ol>
            <li>
                <a href="/" class="vk-focus">Etusivu</a>
            </li>
            <li aria-hidden="true">/</li>
            <li>
                <a href="/sahkosopimus" class="vk-focus">Sähkösopimukset</a>
            </li>
            <li aria-hidden="true">/</li>
            <li aria-current="page">
                {{ $pageHeading }}
            </li>
        </ol>
    </nav>
@endif
