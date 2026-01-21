# Decisions Log

## 2026-01-21: Analysis of Contract Listing Components

### Summary

Analyzed three contract listing implementations to identify shared vs different functionality. The goal is to consolidate these into a single reusable contract card component.

### Components Analyzed

#### 1. ContractsList.php / contracts-list.blade.php
**Route:** `/` (main listing)
**Purpose:** Main contracts listing with full filtering and consumption calculator

**Filters/Parameters:**
- Consumption presets and calculator (extensive)
- `contractTypeFilter` (FixedTerm, OpenEnded)
- `pricingModelFilter` (Spot, FixedPrice, Hybrid)
- `meteringFilter` (General, Time, Season)
- `postcodeFilter`
- `renewableFilter`, `nuclearFilter`, `fossilFreeFilter`

**Data Passed to View:**
- `$contracts` - Collection with `calculated_cost`, `emission_factor` appended
- `$postcodeSuggestions`
- `$pageTitle`, `$metaDescription`

**Card Features:**
- Ranking number (01, 02, etc.)
- Featured badge ("Suositus") for #1
- Company logo, contract name, company name
- Price display (c/kWh or margin for spot)
- Monthly fee (€/kk)
- Annual cost (€/v) - highlighted for featured
- CO2 emissions badge (kg CO₂/v with g/kWh secondary)
- Energy source badges (Uusiutuva %, Ydinvoima %, Fossiilinen %)
- Contract type badge (Pörssi for spot)
- Left border color based on emissions/featured status
- CTA button ("Katso")

#### 2. LocationsList.php / locations-list.blade.php
**Route:** `/paikkakunnat/{location?}`
**Purpose:** Browse contracts by municipality

**Filters/Parameters:**
- `search` - municipality search
- `selectedMunicipality` - filters contracts to that municipality

**Data Passed to View:**
- `$municipalities` - list of municipalities with postcode counts
- `$contracts` - raw contracts for selected municipality (no calculated_cost)

**Card Features:**
- Company logo, contract name, company name
- Energy price (c/kWh) - direct from priceComponents
- Monthly fee (EUR/kk)
- NO annual cost calculation
- NO ranking numbers
- NO featured badges
- NO emissions badges
- NO energy source badges
- Simple CTA ("Katso lisää")

**KEY DIFFERENCE:** This is a much simpler card - no calculated costs, no emissions, no badges.

#### 3. SeoContractsList.php / seo-contracts-list.blade.php
**Route:** `/sahkosopimus/{housingType}`, `/sahkosopimus/{energySource}`, etc.
**Purpose:** SEO landing pages for specific housing types, energy sources, or cities

**Relationship:** EXTENDS ContractsList (inherits all parent functionality)

**Additional Filters:**
- `housingType` (omakotitalo, kerrostalo, rivitalo) - sets default consumption
- `energySource` (tuulisahko, aurinkosahko, vihrea-sahko)
- `city` - filters by municipality postcodes

**Data Passed to View:**
- Everything from parent ContractsList
- `$seoData` (title, description, canonical, jsonLd)
- `$pageHeading`
- `$seoIntroText`
- `$hasSeoFilter`
- `$energySourceStats`
- `$environmentalInfo`
- `$cityInfo`

**Card Features:**
- Company logo, contract name, company name
- Energy price (c/kWh)
- Monthly fee (EUR/kk) with contract_type text
- Annual cost (EUR)
- Energy source badges (Uusiutuva %, Ydinvoima %, Fossiilinen %)
- CTA button ("Katso lisää")
- NO ranking numbers
- NO featured badges
- NO emissions badges (despite parent having emission_factor!)
- NO left border color

### Visual Comparison Summary

| Feature | ContractsList | LocationsList | SeoContractsList |
|---------|---------------|---------------|------------------|
| Ranking # | YES | NO | NO |
| Featured badge | YES | NO | NO |
| Company logo | YES | YES | YES |
| Contract name | YES | YES | YES |
| Company name | YES | YES | YES |
| Energy price c/kWh | YES | YES | YES |
| Spot margin display | YES | NO | NO |
| Monthly fee | YES | YES | YES |
| Annual cost | YES (calculated) | NO | YES (calculated) |
| CO2 emissions badge | YES | NO | NO |
| Energy source badges | YES | NO | YES |
| Contract type badge | YES (Pörssi) | NO | NO |
| Left border (emissions) | YES | NO | NO |
| Card border/shadow | Minimal border | `border shadow-sm` | `border shadow-sm` |
| CTA text | "Katso" | "Katso lisää" | "Katso lisää" |

### Key Findings

1. **ContractsList has the most complete card** - It includes ranking, featured status, emissions, energy sources, and spot contract display. This should be the base for the unified component.

2. **LocationsList uses a simplified card** - Likely intentional as it doesn't calculate costs (contracts are raw from DB without `calculated_cost`). However, it could benefit from energy source badges.

3. **SeoContractsList card is inconsistent** - It inherits calculated costs and emission_factor from parent but doesn't display emissions. It also lacks spot margin display despite inheriting spot contract detection.

4. **Duplication is extensive** - The contract card markup is ~120 lines in each template, with roughly 70-80% similarity.

### Recommended Component Props

Based on analysis, the unified `<x-contract-card>` should accept:

```php
@props([
    'contract',           // Required: ElectricityContract model
    'rank' => null,       // Optional: ranking number (1, 2, 3...)
    'featured' => false,  // Optional: show featured badge
    'consumption' => null, // Required for cost display: kWh for cost calculation
    'showEmissions' => true, // Optional: show CO2 badge
    'showRank' => true,   // Optional: show ranking number
    'showSpotInfo' => true, // Optional: show spot margin for spot contracts
])
```

The component should:
1. Accept a contract with optional `calculated_cost` and `emission_factor` appended
2. Gracefully handle missing calculated data (fallback to priceComponents)
3. Support all visual variants through props
4. Use consistent styling from ContractsList as the baseline

### Migration Strategy

1. Create `components/contract-card.blade.php` using ContractsList card as base
2. Add conditional rendering based on props (rank, featured, emissions)
3. Refactor ContractsList to use the component (most features enabled)
4. Refactor SeoContractsList to use the component (add missing emissions display)
5. Refactor LocationsList to use the component (simplified mode)
6. Test all three pages to ensure visual parity
