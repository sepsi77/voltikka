# Decisions

- Investigated against the live Azure Consumer API (`/api/productlist/00100`) because the local database does not contain the production slug.
- Source API returns `PricingModel: Spot` and a structured `General` price component of `6.49 CentPerKiwattHour`; the structured data does not expose the ongoing `1.99 c/kWh` margin as a separate component.
- Voltikka imports `OriginalPayment.Price` as `price_components.price` and treats a `General` component on a `Spot` contract as the spot margin for all calculations.
