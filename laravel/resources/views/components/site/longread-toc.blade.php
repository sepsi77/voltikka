@props([
    'items' => [],
    'title' => 'Tällä sivulla',
    'label' => 'Sivun sisältö',
])
{{--
    Numbered section list at the left of a long read (design spec §5 "Long reads", Paper
    "Tällä sivulla (TOC)" on boards 49Y-0, 38P-0 and 6HX-0). `items` is a list of
    [anchor id, label]. Sticky on a laptop. A small scroll check marks the section in view
    (fox-deep number, ink link with a fox underline); without JS every item stays plain.
--}}
<nav
    {{ $attributes->class(['vk-lr-toc']) }}
    aria-label="{{ $label }}"
    x-data="{
        active: null,
        ids: {{ \Illuminate\Support\Js::from(array_column($items, 0)) }},
        update() {
            let current = null;
            for (const id of this.ids) {
                const el = document.getElementById(id);
                if (el && el.getBoundingClientRect().top < 140) current = id;
            }
            this.active = current;
        },
    }"
    x-init="update()"
    x-on:scroll.window.throttle.100ms="update()"
>
    <p class="vk-lr-toc-title">{{ $title }}</p>
    <ol class="vk-lr-toc-list">
        @foreach ($items as $index => [$id, $text])
            <li>
                <a href="#{{ $id }}" class="vk-lr-toc-link vk-focus" :class="active === @js($id) && 'is-active'" :aria-current="active === @js($id) ? 'true' : null">
                    <span class="vk-lr-toc-num" aria-hidden="true">{{ $index + 1 }}</span>
                    <span class="vk-lr-toc-text">{{ $text }}</span>
                </a>
            </li>
        @endforeach
    </ol>
    {{ $slot }}
</nav>
