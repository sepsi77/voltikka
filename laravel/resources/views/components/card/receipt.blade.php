@props(['lines'])
{{--
    The itemised price rows. Dotted leaders carry the receipt character; DESIGN.md's single
    family rule means it cannot come from a serif or a monospace face.

    A `soft` row is an estimated figure rather than a contractual one, so the breakdown
    itself shows which parts of the price are known. It is never the only estimate signal;
    an estimated total also carries the Arvio chip in the band.

    Rows are built in App\Services\ContractCard\CardReceiptLines and capped at three there.

    Units are `slate-500`, never `slate-400`. slate-400 on white is 2.56:1, which fails WCAG AA
    (4.5:1) on text that qualifies the numbers households are here to compare. See the
    "Inline units" note in DESIGN.md section 2.
--}}
@if (count($lines) > 0)
    <dl class="min-w-[15rem] max-w-[24rem] flex-[1.4] tabular-nums">
        @foreach ($lines as $line)
            <div class="flex items-baseline gap-2.5 py-1.5 text-sm">
                <dt class="min-w-0 flex-shrink truncate font-medium text-slate-500">{{ $line->label }}</dt>
                <span class="min-w-[1.5rem] flex-1 -translate-y-1 border-b border-dotted border-slate-300" aria-hidden="true"></span>
                <dd class="whitespace-nowrap {{ $line->soft ? 'font-medium text-slate-500' : 'font-bold text-slate-900' }}">
                    {{ $line->value }}@if ($line->unit)<span class="ml-0.5 text-xs font-medium text-slate-500">{{ $line->unit }}</span>@endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
