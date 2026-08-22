# Control crawlable location pagination

## Problem

Location listing pages create many low-value crawlable URLs. Paginated pages repeat shared local and recommended contract blocks. An out-of-range `?page=` value returns HTTP 200, self-canonicalizes, and remains indexable.

## Requirements

- Return HTTP 404 when the requested page is greater than the result set's last page on every public contract listing paginator.
- Do not redirect an out-of-range page to the final valid page.
- On location pages, render shared local and recommended contract blocks only on page 1.
- On valid location pages with page 2 or later, render `<meta name="robots" content="noindex,follow">`.
- Keep valid pagination crawlable. Do not block it in `robots.txt`.
- Preserve current canonical, previous, next, filter, and pagination behavior for valid pages.
- Add focused regression tests.
- Make no production changes.
