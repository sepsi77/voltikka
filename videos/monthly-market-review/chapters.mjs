// Writes the YouTube title and description (with chapters) of each variant.
//
//   node chapters.mjs 2026-10            # the variants with `final` set
//   node chapters.mjs 2026-10 kotia      # these variants
//
// Text comes from the edition's video/script.mjs (CHAPTERS, TITLE, INTRO, DEFAULT_INTRO, FOOTER).
// Output: <out>-description.txt and <out>-title.txt next to the video (edition.out()).
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { edition, readTimeline } from './edition.mjs';

const [month, ...only] = process.argv.slice(2);
const ed = await edition(month);
const { CHAPTERS = {}, TITLE = {}, INTRO = {}, DEFAULT_INTRO = '', FOOTER = () => '' } = ed.script;
const names = only.length ? only : Object.keys(ed.script.VARIANTS).filter((v) => ed.script.VARIANTS[v].final);
const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
for (const v of names) {
    ed.variant(v);
    const L = (await readTimeline(ed.timeline(v))).lines;
    const list = CHAPTERS[v] ?? [];
    const lines = list.map(([name, id]) => `${mmss(id ? Math.max(0, L[id].start - 0.5) : 0)} ${name}`);
    const text = [INTRO[v] ?? DEFAULT_INTRO, lines.join('\n'), FOOTER(v)].filter(Boolean).join('\n\n');
    const out = ed.out(v);
    mkdirSync(dirname(out), { recursive: true });
    writeFileSync(`${out}-description.txt`, text);
    if (TITLE[v]) writeFileSync(`${out}-title.txt`, TITLE[v] + '\n');
    console.log(`== ${v} → ${out}-description.txt\n${lines.join('\n')}`);
}
