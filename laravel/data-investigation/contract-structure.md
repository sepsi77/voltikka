# Contract JSON Structure

Source: `https://ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/{postcode}`

## Top-Level Structure

```
Contract
├── Id: string (UUID)
├── Name: string
├── Company: { ... }
└── Details: { ... }
```

## Company Object

```
Company: {
    Name: string
    CompanyUrl: string
    StreetAddress: string
    PostalCode: string
    PostalName: string
    LogoURL: string
}
```

## Details Object

```
Details: {
    FixedTimeRange: string (e.g., "Fixed12", "Fixed24", "OpenEnded")
    SpotPriceSelection: string (e.g., "NasdaqMonthly", "None")
    Metering: string (e.g., "General", "Time", "Seasonal")
    ElectricitySource: { ... }
    Pricing: { ... }
    AvailabilityArea: { ... }
    ConsumptionLimitation: { ... }
    PreBilling: bool
    AvailableForExistingUsers: bool
    ConsumptionControl: bool
    DeliveryResponsibilityProduct: bool
    OrderLink: string
    ProductLink: string
    ExtraInformation: { ... }
    MicroProduction: { ... }
    BillingFrequency: { ... }
    TimePeriodDefinitions: { ... }
    TransparencyIndex: { ... }
    SpotFutures: float (e.g., 5.602 = current spot price)
    EnergySourceIconView: string
    HasCustomPricingElement: bool
    TargetGroup: string ("Company", "Consumer")
    ContractType: string ("OpenEnded", "FixedTerm")
    PricingModel: string ("Spot", "Fixed", "Hybrid", "Other")
}
```

## ElectricitySource Object

```
ElectricitySource: {
    Renewable: {
        Total: float (percentage)
        BioMass: float
        Solar: float
        Wind: float
        General: float
        Hydro: float
    }
    Fossil: {
        Total: float (percentage)
        Oil: float
        Coal: float
        NaturalGas: float
        Peat: float
    }
    Nuclear: {
        Total: float (percentage)
        General: float
    }
}
```

## Pricing Object

```
Pricing: {
    Id: string (UUID)
    ElectricitySupplyProductId: string (UUID)
    Name: string
    StartDate: string (ISO datetime)
    EndDate: string | null
    PriceComponents: [ ... ]
    HasDiscount: bool
    Created: string (ISO datetime)
    Updated: string (ISO datetime)
}
```

## PriceComponent Object

```
PriceComponent: {
    Id: string (UUID)
    PriceVersionId: string (UUID)
    HasDiscount: bool
    Discount: {
        DiscountValue: float
        IsPercentage: bool
        DiscountType: string ("NoDiscount", ...)
        NFirstKwh: float
        UntilDate: string (ISO datetime)
        NfirstMonths: int
    }
    OriginalPayment: {
        Price: float
        PaymentUnit: string ("EurPerMonth", "CentPerKiwattHour")
    }
    PriceComponentType: string ("Monthly", "General", "DayTime", "NightTime", "SeasonalWinterDay", "SeasonalOther")
    FuseSize: string ("Any", ...)
    Created: string (ISO datetime)
    Updated: string (ISO datetime)
}
```

## AvailabilityArea Object

```
AvailabilityArea: {
    IsNational: bool
    PostalCodes: string[]
    Dsos: string[]
}
```

## ConsumptionLimitation Object

```
ConsumptionLimitation: {
    MinXKWhPerY: int | null
    MaxXKWhPerY: int | null
}
```

## ExtraInformation Object

```
ExtraInformation: {
    Default: string | null
    FI: string (Finnish description)
    EN: string (English description)
    SV: string (Swedish description)
}
```

## MicroProduction Object

```
MicroProduction: {
    Buys: bool (whether company buys microproduction)
    Details: {
        Default: string | null
        FI: string
        EN: string
        SV: string
    }
}
```

## BillingFrequency Object

```
BillingFrequency: {
    Default: string | null
    FI: string (e.g., "Kuukausittain (1/kk)")
    EN: string
    SV: string
}
```

## TimePeriodDefinitions Object

```
TimePeriodDefinitions: {
    DayAndNight: {
        Default: string | null
        FI: string
        EN: string
        SV: string
    }
    WinterOtherSeasons: {
        Default: string | null
        FI: string
        EN: string
        SV: string
    }
}
```

## TransparencyIndex Object

```
TransparencyIndex: {
    IsBelowWholesalePrice: bool
    IsOnlyForNewCustomers: bool
    HasPreBilling: bool
    ProductHasHadErrors: bool
    CompanyHasOtherErroneousProducts: bool
    IsOpenEndedWarning: bool
    IsPermanentProduct: bool
    UsingGeneralTerms: bool
    AvailableOnlyInSingleDso: bool
    ProductErrors: array
    CompanyErrors: array
    TotalErrorCount: int
}
```

---

## Key Fields for Pricing Type Identification

| Field | Values | Description |
|-------|--------|-------------|
| `Details.PricingModel` | "Spot", "Fixed", "Hybrid", "Other" | Primary pricing model type |
| `Details.ContractType` | "OpenEnded", "FixedTerm" | Contract duration type |
| `Details.SpotPriceSelection` | "NasdaqMonthly", "None" | Spot price source |
| `Details.SpotFutures` | float | Current spot price (€/MWh without VAT) |
| `Details.Metering` | "General", "Time", "Seasonal" | Metering/tariff type |
| `Details.TargetGroup` | "Company", "Consumer" | Target customer segment |

## PriceComponentType Values

| Value | Description |
|-------|-------------|
| `Monthly` | Fixed monthly fee (€/month) |
| `General` | Single rate for all hours (c/kWh) |
| `DayTime` | Day rate for Time metering (c/kWh) |
| `NightTime` | Night rate for Time metering (c/kWh) |
| `SeasonalWinterDay` | Winter day rate for Seasonal metering (c/kWh) |
| `SeasonalOther` | Other seasons rate for Seasonal metering (c/kWh) |

## PaymentUnit Values

| Value | Description |
|-------|-------------|
| `EurPerMonth` | Euro per month (for Monthly component) |
| `CentPerKiwattHour` | Cents per kWh (for energy components) |
