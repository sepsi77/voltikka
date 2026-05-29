@props([
    'text',
    'triggerClass' => '',
])
{{--
    Inline "more info" affordance: dotted-underlined trigger text + a small info glyph,
    with a styled tooltip that appears on hover/focus (desktop) or tap (touch). The bubble is
    teleported to <body> and fixed-positioned from the trigger's rect so card overflow/rounded
    corners cannot clip it. Replaces native <abbr title> tooltips, which were slow, unstyled,
    and gave no visible hint that the word was explainable.
--}}
<span
    x-data="{
        open: false,
        x: 0,
        y: 0,
        place() {
            const r = $refs.trigger.getBoundingClientRect();
            this.x = Math.min(Math.max(r.left + r.width / 2, 88), window.innerWidth - 88);
            this.y = r.bottom + 6;
        },
        show() { this.place(); this.open = true; },
    }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="inline"
>
    <button
        type="button"
        x-ref="trigger"
        @mouseenter="show()"
        @mouseleave="open = false"
        @focus="show()"
        @blur="open = false"
        @click.prevent.stop="open ? (open = false) : show()"
        :aria-expanded="open"
        aria-label="Näytä lisätietoa"
        style="font:inherit;color:inherit"
        class="{{ $triggerClass }} group/tip inline cursor-help border-0 bg-transparent p-0 underline decoration-dotted decoration-1 underline-offset-2"
    >{{ $slot }}<svg width="1em" height="1em" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" style="width:0.95em;height:0.95em" class="ml-0.5 inline-block -translate-y-px align-middle opacity-50 transition-opacity group-hover/tip:opacity-100"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9 9a1 1 0 112 0v4a1 1 0 11-2 0V9zm1-5.5a1.25 1.25 0 100 2.5 1.25 1.25 0 000-2.5z" clip-rule="evenodd"/></svg></button>

    <template x-teleport="body">
        <span
            x-show="open"
            x-cloak
            x-transition.opacity.duration.120ms
            :style="`top:${y}px; left:${x}px`"
            role="tooltip"
            class="pointer-events-none fixed z-[100] w-max max-w-[17rem] -translate-x-1/2 rounded-lg bg-slate-900 px-3 py-2 text-xs font-normal normal-case leading-snug tracking-normal text-white shadow-xl ring-1 ring-black/5"
        >{{ $text }}</span>
    </template>
</span>
