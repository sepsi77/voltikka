@props([
    'label',
    'heading' => null,
    'body',
    'linkUrl' => null,
    'linkText' => null,
    'ariaLabel' => null,
    'triggerClass' => '',
])
{{--
    Interactive disclosure: a pill button that opens a panel which the pointer can enter,
    so the panel can hold a link. This is the difference from <x-info-tip>, whose bubble is
    `pointer-events-none` on purpose; a plain tooltip should never swallow the pointer.
    Use info-tip for a sentence of explanation, info-popover when the explanation has to
    link somewhere.

    The panel is teleported to <body> and fixed-positioned from the trigger's rect. It must
    not be a positioned child of the card: the card clips its own corners with
    `overflow-hidden`, and the trigger sits in the card's top band, so an absolutely
    positioned panel would be cut off.

    Hover intent: closing is delayed ~220ms and the panel cancels the timer on enter, so the
    pointer can travel the gap from the trigger into the panel.

    Keyboard/AT: the trigger is a real button with aria-expanded/aria-controls. A click moves
    focus into the panel (it is teleported to the end of <body>, so Tab order alone would
    never reach it); Escape closes and returns focus to the trigger.
--}}
@php
    $popoverId = 'popover-'.\Illuminate\Support\Str::random(8);
@endphp
<span
    x-data="{
        open: false,
        x: 0,
        y: 0,
        closeTimer: null,
        place() {
            const r = $refs.trigger.getBoundingClientRect();
            const width = 272;
            const margin = 12;
            this.x = Math.min(Math.max(r.right - width, margin), Math.max(margin, window.innerWidth - width - margin));
            const below = r.bottom + 8;
            this.y = (below + 240 > window.innerHeight && r.top - 8 > 240) ? r.top - 8 - 240 : below;
        },
        show() {
            clearTimeout(this.closeTimer);
            this.place();
            this.open = true;
        },
        scheduleHide() {
            clearTimeout(this.closeTimer);
            this.closeTimer = setTimeout(() => { this.open = false }, 220);
        },
        cancelHide() { clearTimeout(this.closeTimer) },
        toggle() {
            if (this.open) { this.open = false; return }
            this.show();
            $nextTick(() => $refs.panel?.focus());
        },
        dismiss() {
            clearTimeout(this.closeTimer);
            this.open = false;
            $refs.trigger?.focus();
        },
    }"
    @keydown.escape.window="open && dismiss()"
    class="inline-flex"
>
    <button
        type="button"
        x-ref="trigger"
        @mouseenter="show()"
        @mouseleave="scheduleHide()"
        @focus="show()"
        @click.prevent.stop="toggle()"
        :aria-expanded="open"
        aria-controls="{{ $popoverId }}"
        aria-label="{{ $ariaLabel ?? 'Miten vuosihinnan arvio lasketaan' }}"
        class="{{ $triggerClass }} inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-semibold text-slate-600 transition-colors hover:border-coral-400 hover:text-coral-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500 cursor-help"
    >
        {{ $label }}
        <svg class="h-3.5 w-3.5 opacity-70" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9 9a1 1 0 112 0v4a1 1 0 11-2 0V9zm1-5.5a1.25 1.25 0 100 2.5 1.25 1.25 0 000-2.5z" clip-rule="evenodd"/></svg>
    </button>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-ref="panel"
            id="{{ $popoverId }}"
            tabindex="-1"
            role="dialog"
            aria-label="{{ $heading ?? 'Miten arvio on laskettu' }}"
            @mouseenter="cancelHide()"
            @mouseleave="scheduleHide()"
            @click.outside="open && scheduleHide()"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            :style="`top:${y}px; left:${x}px`"
            class="fixed z-[100] w-[17rem] rounded-xl border border-slate-200 bg-white p-4 text-sm font-normal leading-relaxed text-slate-600 shadow-md focus:outline-none"
        >
            @if ($heading)
                <strong class="mb-1 block font-bold text-slate-900">{{ $heading }}</strong>
            @endif
            {{ $body }}
            @if ($linkUrl && $linkText)
                <a
                    href="{{ $linkUrl }}"
                    class="mt-2.5 inline-block rounded font-bold text-coral-600 no-underline hover:text-coral-500 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500"
                >{{ $linkText }} &rarr;</a>
            @endif
        </div>
    </template>
</span>
