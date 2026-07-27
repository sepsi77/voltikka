# Decisions

- The latest stored seller evidence does not define strict package-specific annual consumption limits. The database range is 0–100,000 kWh/year.
- Each product is a monthly included-energy package: XS 10.50 €/month + 75 kWh/month, S 21 €/month + 150 kWh/month, M 35 €/month + 250 kWh/month, and L 49 €/month + 350 kWh/month. Use above the allowance costs 16.60 c/kWh in that calendar month.
- `NFirstKwh` values 900/1,800/3,000/4,200 equal 12 times the monthly allowance. In this exact verified package shape they are package metadata, not a promotion or purchase cap.
- Validator v17 recognizes both the existing “ylittävästä energiasta laskutamme” wording and Vaasan Sähkö's “lisäenergian hintaa sovelletaan ... kalenterikuukauden aikana ylittää ... energiamäärän” wording. It still requires package wording, exactly one positive Monthly source row, exactly one positive General excess-rate source row, and a positive numeric monthly allowance.
- If a package General row uses `NFirstKwh` or has a positive first-kWh marker, its active-discount flag, non-percentage discount value, rate, annual kWh marker, and monthly allowance must agree. Otherwise it is not exempt from ordinary unsupported discount validation.
- Correct canonical output is one phase with no billed components and one typed monthly package object. The existing parser, calculator, and card presenter then apply and display the package correctly.
- Detail metadata suppresses a package excess-use rate as an ordinary energy price. Product JSON-LD names it `Ylittävä kulutus`; the generated qualifier and mechanism FAQ state the monthly fee, included kWh, and excess rate.
- Exact-source validator coverage includes all four XS/S/M/L source shapes. Controls reject ordinary first-kWh promotions, incompatible annual markers/discount amounts, ordinary component output, duplicate package charges, and duplicate positive source fees.
- Existing canonical interpretations must be regenerated because their published JSON is wrong. The validator version changes from v16 to v17; schema and prompt versions do not change.
