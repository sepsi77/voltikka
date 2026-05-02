# Cache contract statistics page

Optimize `/sahkosopimus/tilastot` so expensive statistics queries/calculations are not repeated on every page load. The page updates once per day, so serving a cached rendered/data version is acceptable.
