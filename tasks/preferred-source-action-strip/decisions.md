# Decisions

- Use Google's documented advanced manual Preferred Sources integration instead of the declarative `google-add-preferred-source-btn` renderer. The declarative renderer inserts a cross-origin iframe, so a parent handler cannot reliably observe the activation for Plausible.
- Load `publisher.js` with `preferred-sources-control="manual"`, initialize the `PREFERRED_SOURCE` callback client with only `theme: 'light'`, and expose that ready client to the native action. Google uses the page or browser language.
- Use a native button so analytics and `addPreferredSource()` run in the same direct user gesture. Route the Alpine `$track` event through a local `try/catch` method, then always call the official API. Plausible must not block Google's flow.
- Bound the disabled loading state to 8 seconds. On timeout, show Google's documented `https://www.google.com/preferences/source?q=voltikka.fi` deeplink with the same action anatomy; also provide a `noscript` deeplink. Keep listening for a late ready event so the native action can replace the fallback. The fallback uses the same non-blocking tracking method without preventing navigation.
- The first implementation limited the strip to `/sahkosopimus`. The final scope is every real public HTML page, so there is no Livewire visibility property.
- Place the component explicitly in each of the 19 public page Blade templates. Do not use DOM movement or a layout-wide injection. Keep the internal contract comparison widget free of the strip.
- Make `x-page-action-strip` self-contained with default Finnish copy and the built-in Google action. Keep its optional slot after that action for future page tools.
- Use the global Plausible property `placement=post_hero` with the existing event name `Google Preferred Source Clicked`.
- Keep the large homepage hero-to-content interval by moving it to top padding on the first service section. On editorial statistics and forecast pages, keep their 48 px content interval as component bottom margin after removing it from the header.
