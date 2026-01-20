# Design Decisions

## Hero Section Pattern

All pages should have a consistent dark hero section using this pattern:

### Structure
```html
<section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="py-12 lg:py-16">
            <!-- Content here -->
        </div>
    </div>
</section>
```

### Typography
- **h1**: `text-4xl md:text-5xl xl:text-6xl font-extrabold text-white tracking-tight leading-tight`
- **Coral accent**: Wrap key word in `<span class="text-coral-400">word</span>`
- **Description**: `text-slate-300 md:text-lg lg:text-xl`

### Accent Words by Page
- Homepage: "sähkösopimus"
- Spot Price: "pörssisähkön" or "hinta"
- Locations: "paikkakunnittain"
- Calculator: "laskuri" or "kulutus"
- Company: company name (dynamic)

### Negative Margins
The `-mx-4 sm:-mx-6 lg:-mx-8` creates a full-bleed effect by extending beyond the page container. This is essential for the dark hero to span the full viewport width.

### Content After Hero
Content after the hero should be wrapped in:
```html
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Page content -->
</div>
```
