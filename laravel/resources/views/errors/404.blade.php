{{--
    404 page (Paper AO6-0; replaces Laravel's default English page). Laravel renders this view for
    every 404 with status 404. It uses the redesigned site layout (top bar over the forest hero,
    footer). The footer reads a cached contract count from the database; if the layout cannot
    render (for example the database is down), the same content renders in a minimal page so a
    missing page still answers 404 with Finnish text instead of an error page.
--}}
@php
    use App\Support\Season;
    use Illuminate\Support\Facades\Blade;
    use Illuminate\Support\HtmlString;

    $notFoundContent = new HtmlString(Blade::render('<x-site.longread-404 />'));

    try {
        $notFoundPage = view('layouts.app', [
            'slot' => $notFoundContent,
            'title' => 'Sivua ei löytynyt | Voltikka',
            'metaDescription' => 'Etsimääsi sivua ei löytynyt. Vertaa sähkösopimuksia, katso pörssisähkön hinta tai palaa Voltikan etusivulle.',
            'robots' => 'noindex, follow',
            'heroTone' => Season::heroTone('404-forest'),
        ])->render();
    } catch (\Throwable $layoutFailure) {
        $notFoundPage = null;
    }
@endphp
@if ($notFoundPage !== null)
{!! $notFoundPage !!}
@else
<!DOCTYPE html>
<html lang="fi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, follow">
        <title>Sivua ei löytynyt | Voltikka</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css'])
        @endif
    </head>
    <body class="font-sans antialiased bg-ground text-ink-body">
        <main>{{ $notFoundContent }}</main>
    </body>
</html>
@endif
