// Voice comparison: the same Katsaus passage with several TTS models and voices.
//
//   node voice-test.mjs 2026-10          # makes work/audio/voice-test/<nn>-<name>.mp3 and results.json in the edition
//   node voice-test.mjs 2026-10 03 07    # only these candidates
//
// The agent cannot listen. Each file is transcribed: "missing" lists words of the passage that the
// transcript does not have (unclear Finnish), "extra" lists heard words that are not in the passage.
// Words per second shows the tempo (MAI Harper at speed 1 was 1.6 and sounded too slow).
import { execFile } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync, readFileSync } from 'node:fs';
import { promisify } from 'node:util';

const run = promisify(execFile);
import { edition, GENERATE_AUDIO } from './edition.mjs';

const [month, ...only] = process.argv.slice(2);
const dir = `${(await edition(month)).work}/audio/voice-test`;
mkdirSync(dir, { recursive: true });

const TEXT = 'Lokakuu oli halpa. Talvesta tulee kallis. Sähköpörssissä tammikuun sähkö maksaa nyt lähes 16 senttiä kilowattitunnilta. Se on yli kolme kertaa lokakuun hinta. '
    + 'Marraskuun ensimmäisenä maanantaina tuulivoimaa oli vain kolmannes lokakuun tasosta. Illalla pörssisähkö maksoi 51 senttiä kilowattitunnilta. '
    + 'Siksi neuvomme: valitse nyt 24 kuukauden kiinteä hinta.';
const STYLE = 'Speak Finnish like an energetic, confident news presenter on a consumer finance show: brisk tempo, clear and lively, friendly but authoritative. Not sleepy, not monotone.';
// ElevenLabs v3/v4 audio tags go into the text itself.
const TAGGED = '[confident] Lokakuu oli halpa. [serious] Talvesta tulee kallis. Sähköpörssissä tammikuun sähkö maksaa nyt lähes 16 senttiä kilowattitunnilta. Se on yli kolme kertaa lokakuun hinta. '
    + '[dramatic] Marraskuun ensimmäisenä maanantaina tuulivoimaa oli vain kolmannes lokakuun tasosta. Illalla pörssisähkö maksoi 51 senttiä kilowattitunnilta. '
    + '[confident] Siksi neuvomme: valitse nyt 24 kuukauden kiinteä hinta.';
// eleven_v4 refuses speed (HTTP 400), so it gets no speed setting.
const EL = (speed) => JSON.stringify({ elevenlabs: { voice_settings: { ...(speed ? { speed } : {}), stability: 0.4 }, language_code: 'fi' } });

const C = [
    ['01-mai-harper-1.0', 'microsoft/mai-voice-2.1', 'fi-FI-Harper:MAI-Voice-2.1', {}],
    ['02-mai-harper-1.2', 'microsoft/mai-voice-2.1', 'fi-FI-Harper:MAI-Voice-2.1', { speed: 1.2 }],
    ['03-mai-grant-1.2', 'microsoft/mai-voice-2.1', 'fi-FI-Grant:MAI-Voice-2.1', { speed: 1.2 }],
    ['04-eleven-v4-sarah', 'elevenlabs/eleven-v4', 'sarah', { text: TAGGED, po: EL() }],
    ['05-eleven-v4-alice', 'elevenlabs/eleven-v4', 'alice', { text: TAGGED, po: EL() }],
    ['06-eleven-v4-george', 'elevenlabs/eleven-v4', 'george', { text: TAGGED, po: EL() }],
    ['07-eleven-v3-laura', 'elevenlabs/eleven-v3', 'laura', { text: TAGGED, po: EL(1.1) }],
    // v4 has no speed setting: test a pace tag in the text.
    ['16-eleven-v4-sarah-fasttag', 'elevenlabs/eleven-v4', 'sarah', { text: '[energetic, fast-paced news presenter] ' + TAGGED, po: EL() }],
    ['17-eleven-v4-george-fasttag', 'elevenlabs/eleven-v4', 'george', { text: '[energetic, fast-paced news presenter] ' + TAGGED, po: EL() }],
    ['08-eleven-multilingual-v2-matilda', 'elevenlabs/eleven-multilingual-v2', 'matilda', { po: EL(1.1) }],
    ['09-gemini-3.8-kore', 'google/gemini-3.8-flash-tts', 'Kore', { instructions: STYLE, format: 'pcm' }],
    ['10-gemini-3.8-charon', 'google/gemini-3.8-flash-tts', 'Charon', { instructions: STYLE, format: 'pcm' }],
    ['11-gemini-3.8-aoede', 'google/gemini-3.8-flash-tts', 'Aoede', { instructions: STYLE, format: 'pcm' }],
    ['12-fish-s2.1-pro', 'fish-audio/s2.1-pro', null, { instructions: STYLE }],
    ['13-grok-eve', 'x-ai/grok-voice-tts-1.0', 'eve', {}],
    ['14-grok-rex', 'x-ai/grok-voice-tts-1.0', 'rex', {}],
];

const norm = (s) => s.toLowerCase().replace(/\[[^\]]*\]/g, ' ').replace(/[^a-zåäö\s]/g, ' ').split(/\s+/).filter((w) => w.length > 2);
const NUM = /(nolla|yks|yhd|kaks|kahd|kolm|nelj|viis|viid|kuus|kuud|seitsem|kahdeks|yhdeks|kymmen|toista|sata|sada|tuha)/;
const words = norm(TEXT);
const results = existsSync(`${dir}/results.json`) ? JSON.parse(readFileSync(`${dir}/results.json`, 'utf8')) : {};

async function make([name, model, voice, o]) {
    const ext = o.format === 'pcm' ? 'wav' : 'mp3';
    const out = `${dir}/${name}.${ext}`;
    const args = [GENERATE_AUDIO, 'speech', '--model', model, '--text', o.text ?? TEXT, '--out', out];
    if (voice) args.push('--voice', voice);
    if (o.speed) args.push('--speed', String(o.speed));
    if (o.instructions) args.push('--instructions', o.instructions);
    if (o.po) args.push('--provider-options', o.po);
    if (o.format) args.push('--format', o.format);
    try {
        await run('node', args, { timeout: 180000 });
    } catch (e) {
        results[name] = { error: String(e.stderr || e.message).split('\n').find((l) => l.includes('HTTP')) || String(e.message).slice(0, 300) };
        console.log(`FAIL ${name}: ${results[name].error}`);
        return;
    }
    // Trim silence at the ends so the tempo is comparable.
    const trimmed = `${dir}/.${name}.wav`;
    await run('ffmpeg', ['-loglevel', 'error', '-y', '-i', out, '-af',
        'silenceremove=start_periods=1:start_threshold=-45dB,areverse,silenceremove=start_periods=1:start_threshold=-45dB,areverse', trimmed]);
    const dur = Number((await run('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', trimmed])).stdout);
    const heard = (await run('node', [GENERATE_AUDIO, 'transcribe', '--file', trimmed])).stdout.trim();
    const got = norm(heard);
    const missing = words.filter((w) => !got.some((g) => g.startsWith(w.slice(0, 5))));
    const extra = got.filter((g) => !words.some((x) => x.startsWith(g.slice(0, 5)) || g.startsWith(x.slice(0, 5))) && !NUM.test(g));
    results[name] = { model, voice, file: out, dur: +dur.toFixed(2), wps: +(TEXT.split(/\s+/).length / dur).toFixed(2), missing, extra, heard };
    console.log(`${missing.length + extra.length ? 'CHECK' : 'ok   '} ${name} ${dur.toFixed(1)} s, ${results[name].wps} w/s ${missing.length ? 'missing: ' + missing.join(' ') : ''} ${extra.length ? 'extra: ' + extra.join(' ') : ''}`);
}

const queue = C.filter(([n]) => !only.length || only.some((o) => n.startsWith(o)));
await Promise.all(Array.from({ length: 4 }, async () => { while (queue.length) await make(queue.shift()); }));
writeFileSync(`${dir}/results.json`, JSON.stringify(results, null, 2));
