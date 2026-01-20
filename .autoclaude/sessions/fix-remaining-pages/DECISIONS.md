# Design Decisions

## Color Mappings

| Old Class | New Class | Notes |
|-----------|-----------|-------|
| text-tertiary-500 | text-slate-900 | Primary headings |
| text-primary-600 | text-coral-600 | Links |
| bg-primary-500 | bg-coral-500 | Backgrounds |
| bg-primary-50/100 | bg-coral-50/100 | Light backgrounds |
| cyan-500/600 | coral-500/600 | Accent elements |
| border-primary-* | border-coral-* | Borders |
| ring-primary-* | ring-coral-* | Focus rings |
| bg-success-* | bg-green-* | Keep for energy badges (semantic) |

## Component Patterns

### Buttons
```html
<!-- Primary CTA -->
<button class="bg-gradient-to-r from-coral-500 to-coral-600 text-white font-bold rounded-xl shadow-coral hover:from-coral-400 hover:to-coral-500">

<!-- Secondary -->
<button class="bg-slate-900 text-white rounded-xl hover:bg-slate-800">
```

### Cards
```html
<div class="bg-white rounded-2xl border border-slate-100 p-6">
```

### Links
```html
<a class="text-coral-600 hover:text-coral-700">
```

### Active/Selected States
```html
<button class="bg-gradient-to-r from-coral-500 to-coral-600 text-white shadow-coral">
```

### Input Focus States
```html
<input class="focus:border-coral-500 focus:ring-coral-500">
```
