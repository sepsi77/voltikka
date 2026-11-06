// Builds the timeline of one variant from the measured voice lines.
//
//   node timeline.mjs katsaus
//   node timeline.mjs katsaus --srt     # only the subtitles
//
// Writes:
//   timeline/<variant>.js        window.TL = { total, lines: { id: { start, end, text } } } for the scene
//   audio/<variant>/voice.wav    the voice track: each line at its start time (48 kHz mono)
//   final/…-katsaus.srt (other variants: out/archive/) subtitles (one cue per line, long lines split in two cues)
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { LINES, SUBS, VARIANTS, voDir } from './script.mjs';

const variant = process.argv[2];
const srtOnly = process.argv[3] === '--srt';
const v = VARIANTS[variant];
if (!v) throw new Error(`Unknown variant: ${variant}`);
const dir = voDir(v);
const duration = (f) => Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', f]).toString());

const lines = {};
const order = [];
let t = v.lead;
for (const [id, pause] of v.segments) {
    const d = duration(`${dir}/${id}.wav`);
    lines[id] = { start: +t.toFixed(3), end: +(t + d).toFixed(3), text: LINES[id] };
    order.push(id);
    t += d + pause;
}
const total = +t.toFixed(2);

mkdirSync('timeline', { recursive: true });
if (!srtOnly) writeFileSync(`timeline/${variant}.js`, `// Made by timeline.mjs from the voice durations. Do not edit.\nwindow.TL = ${JSON.stringify({ variant, total, order, lines }, null, 1)};\n`);

// Voice track: silence + line, in order. The concat demuxer keeps sample-exact timing.
const work = `audio/${variant}`;
if (!srtOnly) {
mkdirSync(`${work}/gaps`, { recursive: true });
let list = '';
let pos = 0;
order.forEach((id, i) => {
    const gap = lines[id].start - pos;
    const g = `${work}/gaps/${i}.wav`;
    execFileSync('ffmpeg', ['-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=mono', '-t', gap.toFixed(4), '-c:a', 'pcm_s16le', g]);
    list += `file '${process.cwd()}/${g}'\nfile '${process.cwd()}/${dir}/${id}.wav'\n`;
    pos = lines[id].start + duration(`${dir}/${id}.wav`);
});
writeFileSync(`${work}/voice-list.txt`, list);
execFileSync('ffmpeg', ['-loglevel', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', `${work}/voice-list.txt`,
    '-af', `apad=whole_dur=${total}`, '-ar', '48000', '-ac', '1', '-c:a', 'pcm_s16le', `${work}/voice.wav`]);
}

// Subtitles: split a line at sentence ends into cues of at most about 90 characters,
// with time shared by character count.
const stamp = (s) => {
    const ms = Math.round(s * 1000);
    const p = (n, w = 2) => String(n).padStart(w, '0');
    return `${p(Math.floor(ms / 3600000))}:${p(Math.floor(ms / 60000) % 60)}:${p(Math.floor(ms / 1000) % 60)},${p(ms % 1000, 3)}`;
};
let srt = '';
let n = 1;
for (const id of order) {
    const { start, end } = lines[id];
    const text = SUBS[id] ?? LINES[id];
    // Sentence ends: '.', '?' or '!' before a capital or a digit (so '30. lokakuuta' and 'voltikka.fi' stay whole).
    const parts = text.split(/(?<=[.?!])\s+(?=[A-ZÅÄÖ0-9])/).map((s) => s.trim()).filter(Boolean);
    const cues = [];
    for (const p of parts) {
        if (cues.length && (cues.at(-1) + ' ' + p).length <= 90) cues[cues.length - 1] += ' ' + p;
        else cues.push(p);
    }
    const chars = cues.reduce((a, c) => a + c.length, 0);
    let c0 = start;
    for (const c of cues) {
        const c1 = c0 + (end - start) * c.length / chars;
        srt += `${n++}\n${stamp(c0)} --> ${stamp(c1)}\n${c}\n\n`;
        c0 = c1;
    }
}
mkdirSync('final', { recursive: true });
mkdirSync('out/archive', { recursive: true });
writeFileSync(`${variant === 'katsaus' ? 'final' : 'out/archive'}/voltikka-markkinakatsaus-2026-10-${variant}.srt`, srt.trimEnd() + '\n');
console.log(`${variant}: ${order.length} lines, ${total} s (${Math.floor(total / 60)}:${String(Math.round(total % 60)).padStart(2, '0')})`);
