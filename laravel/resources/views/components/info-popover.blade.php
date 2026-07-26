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

    LIVEWIRE MORPH. Three rules below exist only because this panel lives outside the Livewire
    component root, and undoing any of them puts the panel back in the top-left corner of the
    viewport after the first filter click. The measured failure was: click any filter pill,
    then open an Arvio chip, and the panel renders at 0,0 detached from its chip.

    1. The panel carries a CONSTANT `wire:key`, and it must keep one. Livewire reaches a
       teleported node only through the `from._x_teleport <-> to._x_teleport` bridge in its
       morph, and that bridge runs the normal key comparison first. Livewire's morph key falls
       back to `el.id` when there is no `wire:key`, and the id used to be `Str::random(8)`,
       freshly drawn on every render. Two random ids never match, so every Livewire update (a
       filter, a consumption change, pagination) replaced the live panel with a raw
       `cloneNode(true)`: identical markup, no Alpine scope. Alpine re-initialised that copy
       against an empty scope, so `x-show="open"` resolved `window.open` ("Illegal
       invocation"), the style binding threw "y is not defined" and wrote the literal string
       "undefined" into the style attribute, and the `fixed` panel fell back to the viewport
       origin. One shared key value is safe here: this node is only ever compared 1:1 with its
       own teleport counterpart, never as one of a keyed list of siblings.
    2. Position and id are written IMPERATIVELY on the panel each time it opens, not through
       `:style` / `:id` bindings. The server markup inside <template> carries neither
       attribute, so a morph strips whatever Alpine wrote; a reactive binding would not
       re-run afterwards, because x and y had not changed. Writing them on open means the
       panel is correct after any number of morphs, and it cannot inherit a stale rect.
    3. The trigger is re-resolved on open rather than read from a node cached at init.
--}}
<span
    x-data="{
        open: false,
        closeTimer: null,
        panelId: 'popover-' + Math.random().toString(36).slice(2, 10),
        anchor() {
            const cached = this.$refs.trigger;
            return cached && cached.isConnected ? cached : this.$el.querySelector('[x-ref=\'trigger\']');
        },
        panelEl() {
            const cached = this.$refs.panel;
            if (cached && cached.isConnected) return cached;
            return this.$el.querySelector('template')?._x_teleport ?? null;
        },
        place() {
            const trigger = this.anchor();
            const panel = this.panelEl();
            if (! trigger || ! panel) return false;
            const r = trigger.getBoundingClientRect();
            const width = 272;
            const margin = 12;
            const x = Math.min(Math.max(r.right - width, margin), Math.max(margin, window.innerWidth - width - margin));
            const below = r.bottom + 8;
            const y = (below + 240 > window.innerHeight && r.top - 8 > 240) ? r.top - 8 - 240 : below;
            panel.id = this.panelId;
            panel.style.top = y + 'px';
            panel.style.left = x + 'px';
            return true;
        },
        show() {
            clearTimeout(this.closeTimer);
            if (! this.place()) return;
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
            $nextTick(() => this.panelEl()?.focus());
        },
        dismiss() {
            clearTimeout(this.closeTimer);
            this.open = false;
            this.anchor()?.focus();
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
        :aria-controls="panelId"
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
            wire:key="info-popover-panel"
            x-init="$el.id = panelId"
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
