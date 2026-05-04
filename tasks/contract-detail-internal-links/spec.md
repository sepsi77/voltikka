# Contract detail internal links

Improve internal linking from individual contract detail pages.

## Requirements

- Link the company name in the contract detail hero to the corresponding company detail page when a company slug exists.
- Link contract attribute badges in the hero to existing indexable comparison pages:
  - fixed-term contracts -> `/sahkosopimus/maaraaikainen`
  - open-ended contracts -> `/sahkosopimus/toistaiseksi`
  - spot pricing -> `/sahkosopimus/porssisahko`
  - hybrid pricing -> `/sahkosopimus/joustosahko`
  - general metering fixed-price contracts -> `/sahkosopimus/yleissahko`
  - time metering -> `/sahkosopimus/aikasahko`
  - seasonal metering -> `/sahkosopimus/kausisahko`
- Preserve existing visual appearance with accessible hover/focus states.
- Avoid creating new thin SEO pages for exact duration buckets such as 12 kk.
- Add tests for the internal links.
- Update nearby agent documentation because this is SEO/internal-link behavior.
