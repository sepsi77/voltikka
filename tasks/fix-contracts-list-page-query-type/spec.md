# Fix ContractsList page query TypeError

Sentry reports production TypeError on `/sahkosopimus/paikkakunnat/pudasjarvi`: Livewire attempts to assign an empty query-string value (`?page=`) to typed `int` property `App\Livewire\ContractsList::$page` inherited by `SeoContractsList`.

Goal: make the paginated SEO contracts page robust to empty or invalid page query values without breaking normal pagination/canonical behavior.

Outcome: `$page` accepts Livewire's raw string query value during hydration and is normalized to a positive integer before render, cache decisions, and SEO URL generation.
