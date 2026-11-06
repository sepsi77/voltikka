// Makes the voice lines of one variant and checks each take with a transcript.
//
//   node voice.mjs katsaus            # only missing lines
//   node voice.mjs katsaus k_oct2     # make these lines again
//
// Each line: speech (MAI voice) → trim silence → audio/vo/<voice>/<id>.wav (48 kHz mono) →
// transcribe. A take passes when every word of the line without digits is in the transcript
// (the transcript writes numbers as words) and no other word is heard except Finnish number words. A failed take is made again, at most 3 times; the
// best take stays and the log (audio/vo/<voice>/check.json) shows it.
import { execFileSync, execFile } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { promisify } from 'node:util';
import { LINES, VARIANTS, voDir } from './script.mjs';

const run = promisify(execFile);
const [variant, ...only] = process.argv.slice(2);
const v = VARIANTS[variant];
if (!v) throw new Error(`Unknown variant: ${variant}`);
// Each voice (and MAI speed) has its own folder, so other variants keep their takes.
const dir = voDir(v);
const voiceName = dir.split('/').pop();
mkdirSync(dir, { recursive: true });
const logFile = `${dir}/check.json`;
const log = existsSync(logFile) ? JSON.parse(readFileSync(logFile, 'utf8')) : {};

const ids = [...new Set(v.segments.map(([id]) => id))].filter((id) =>
    only.length ? only.includes(id) : !existsSync(`${dir}/${id}.wav`) || log[id]?.text !== LINES[id]);

const norm = (s) => s.toLowerCase().replace(/[^a-zåäö\s]/g, ' ').split(/\s+/).filter((w) => w.length > 2);

async function take(id, n) {
    const mp3 = `${dir}/raw/${id}-${n}.${v.format === 'pcm' ? 'pcm.wav' : 'mp3'}`;
    mkdirSync(`${dir}/raw`, { recursive: true });
    const args = ['../generate-audio.mjs', 'speech', '--model', v.model ?? 'microsoft/mai-voice-2.1', '--voice', v.voice,
        '--text', (v.tag ? v.tag + ' ' : '') + LINES[id], '--out', mp3];
    if (v.speed) args.push('--speed', String(v.speed));
    if (v.instructions) args.push('--instructions', v.instructions);
    if (v.providerOptions) args.push('--provider-options', JSON.stringify(v.providerOptions));
    if (v.format) args.push('--format', v.format);
    await run('node', args, { timeout: 180000 });
    const wav = `${dir}/raw/${id}-${n}.wav`;
    await run('ffmpeg', ['-loglevel', 'error', '-y', '-i', mp3, '-af',
        'silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.02,areverse,silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.05,areverse',
        '-ar', '48000', '-ac', '1', wav]);
    const { stdout } = await run('node', ['../generate-audio.mjs', 'transcribe', '--file', wav]);
    const heard = stdout.trim();
    const words = norm(LINES[id].replace(/\d[\d\s,]*/g, ' '));
    const got = norm(heard);
    // Finnish endings change little between takes; compare the first 5 letters of each word.
    const missing = words.filter((w) => !got.some((g) => g.startsWith(w.slice(0, 5))));
    // Words heard that are neither in the line nor Finnish number words: for example a number read in English.
    const all = norm(LINES[id]);
    const NUM = /(nolla|yks|yhd|kaks|kahd|kolm|nelj|viis|viid|kuus|kuud|seitsem|kahdeks|yhdeks|kymmen|toista|sata|sada|tuha|pilkku|piste|miinus|plus|ensimm|toinen)/;
    const extra = got.filter((g) => !all.some((x) => x.startsWith(g.slice(0, 5)) || g.startsWith(x.slice(0, 5))) && !NUM.test(g));
    missing.push(...extra.map((x) => `+${x}`));
    return { wav, heard, missing, score: missing.length };
}

async function make(id) {
    let best;
    for (let n = 1; n <= 3; n++) {
        const t = await take(id, n);
        if (!best || t.score < best.score) best = t;
        if (t.score === 0) break;
    }
    execFileSync('cp', [best.wav, `${dir}/${id}.wav`]);
    const dur = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', `${dir}/${id}.wav`]).toString());
    log[id] = { text: LINES[id], heard: best.heard, missing: best.missing, dur };
    console.log(`${best.missing.length ? 'CHECK' : 'ok   '} ${id} ${dur.toFixed(2)} s ${best.missing.length ? '— missing: ' + best.missing.join(', ') : ''}`);
}

// Four lines at a time.
const queue = [...ids];
await Promise.all(Array.from({ length: 4 }, async () => { while (queue.length) await make(queue.shift()); }));
writeFileSync(logFile, JSON.stringify(log, null, 2));
console.log(`${ids.length} lines made for ${variant} (${voiceName}).`);
