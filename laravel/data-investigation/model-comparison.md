# Model Comparison: API vs Laravel

## Missing Fields (API has, Laravel doesn't)

### Critical

| Laravel Field | API Field | Values | Notes |
|--------------|-----------|--------|-------|
| `pricing_model` | `Details.PricingModel` | "Spot", "FixedPrice", "Hybrid" | **CRITICAL** - needed for filtering spot contracts |
| `target_group` | `Details.TargetGroup` | "Household", "Company", "Both" | Needed to filter consumer vs business contracts |

### Optional

| Laravel Field | API Field | Values | Notes |
|--------------|-----------|--------|-------|
| `energy_source_icon_view` | `Details.EnergySourceIconView` | "SingleEnergySource", etc. | UI hint |
| `has_custom_pricing_element` | `Details.HasCustomPricingElement` | bool | Pricing complexity flag |
| `pricing_start_date` | `Details.Pricing.StartDate` | datetime | When pricing became effective |
| `pricing_end_date` | `Details.Pricing.EndDate` | datetime/null | When pricing expires |
| `dsos[]` | `Details.AvailabilityArea.Dsos` | string[] | Distribution System Operators |

## Unique Values for Key Fields

### PricingModel (MISSING - need to add)
- `Spot` - Pörssisähkö contracts
- `FixedPrice` - Fixed rate contracts
- `Hybrid` - Mix of spot + fixed

### TargetGroup (MISSING - need to add)
- `Household` - Consumer/residential
- `Company` - Business customers
- `Both` - Available to both

### ContractType (exists as `contract_type`)
- `FixedTerm` - Määräaikainen
- `OpenEnded` - Toistaiseksi voimassa

### Metering (exists as `metering`)
- `General` - Yleismittarointi
- `Time` - Aikamittarointi
- `Season` - Kausimittarointi (note: API uses "Season", not "Seasonal")

### FixedTimeRange (exists as `fixed_time_range`)
- `Below6` - Under 6 months
- `Fixed6` - 6 months
- `Between711` - 7-11 months
- `Fixed12` - 12 months
- `Between1323` - 13-23 months
- `Fixed24` - 24 months
- `Over24` - Over 24 months
- `Other` - Other duration

## Current Field Mapping

### ElectricityContract ✓
| Laravel | API |
|---------|-----|
| `id` | `Id` |
| `name` | `Name` |
| `company_name` | `Company.Name` |
| `contract_type` | `Details.ContractType` |
| `spot_price_selection` | `Details.SpotPriceSelection` |
| `fixed_time_range` | `Details.FixedTimeRange` |
| `metering` | `Details.Metering` |
| `consumption_control` | `Details.ConsumptionControl` |
| `consumption_limitation_min_x_kwh_per_y` | `Details.ConsumptionLimitation.MinXKWhPerY` |
| `consumption_limitation_max_x_kwh_per_y` | `Details.ConsumptionLimitation.MaxXKWhPerY` |
| `pre_billing` | `Details.PreBilling` |
| `available_for_existing_users` | `Details.AvailableForExistingUsers` |
| `delivery_responsibility_product` | `Details.DeliveryResponsibilityProduct` |
| `order_link` | `Details.OrderLink` |
| `product_link` | `Details.ProductLink` |
| `billing_frequency` | `Details.BillingFrequency` |
| `time_period_definitions` | `Details.TimePeriodDefinitions` |
| `transparency_index` | `Details.TransparencyIndex` |
| `extra_information_*` | `Details.ExtraInformation.*` |
| `availability_is_national` | `Details.AvailabilityArea.IsNational` |
| `microproduction_*` | `Details.MicroProduction.*` |

### Company ✓
| Laravel | API |
|---------|-----|
| `name` | `Company.Name` |
| `company_url` | `Company.CompanyUrl` |
| `street_address` | `Company.StreetAddress` |
| `postal_code` | `Company.PostalCode` |
| `postal_name` | `Company.PostalName` |
| `logo_url` | `Company.LogoURL` |

### PriceComponent ✓
| Laravel | API |
|---------|-----|
| `id` | `PriceComponents[].Id` |
| `price_component_type` | `PriceComponents[].PriceComponentType` |
| `fuse_size` | `PriceComponents[].FuseSize` |
| `has_discount` | `PriceComponents[].HasDiscount` |
| `discount_*` | `PriceComponents[].Discount.*` |
| `price` | `PriceComponents[].OriginalPayment.Price` |
| `payment_unit` | `PriceComponents[].OriginalPayment.PaymentUnit` |

### ElectricitySource ✓
| Laravel | API |
|---------|-----|
| `renewable_*` | `ElectricitySource.Renewable.*` |
| `fossil_*` | `ElectricitySource.Fossil.*` |
| `nuclear_*` | `ElectricitySource.Nuclear.*` |

## Recommendations

1. **Add `pricing_model`** column to `electricity_contracts` table - this is critical for proper filtering
2. **Add `target_group`** column - needed to filter household vs company contracts
3. **Fix `metering` values** - API uses "Season" but we might have "Seasonal" in database
