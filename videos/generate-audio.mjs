// Generates voice and music through OpenRouter.
//
//   node generate-audio.mjs speech --model elevenlabs/eleven-v3 --voice george --text "..." --out audio/voice.mp3
//   node generate-audio.mjs music  --model google/lyria-3-clip-preview --prompt "..." --out audio/music.mp3
//
// The API key is OPENROUTER_API_KEY from ../laravel/.env (the project key), else from the
// environment. The project file wins because the shell can hold an unrelated key. It is never printed.

import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const [command, ...rest] = process.argv.slice(2);
const option = (key, fallback) => {
    const i = rest.indexOf(`--${key}`);
    return i === -1 ? fallback : rest[i + 1];
};

const envFile = new URL('../laravel/.env', import.meta.url);
const key = (existsSync(envFile) && readFileSync(envFile, 'utf8').match(/^OPENROUTER_API_KEY=["']?([^"'\n]+)/m)?.[1])
    || process.env.OPENROUTER_API_KEY;
if (!key) throw new Error('OPENROUTER_API_KEY not found');
const headers = { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' };

const out = resolve(option('out', command === 'music' ? 'audio/music.mp3' : 'audio/voice.mp3'));
mkdirSync(dirname(out), { recursive: true });

if (command === 'speech') {
    // Some models (Gemini TTS) return only raw PCM: 24 kHz, 16-bit, mono. It is wrapped as WAV.
    const format = option('format', 'mp3');
    const body = { model: option('model', 'elevenlabs/eleven-v3'), input: option('text'), voice: option('voice'), response_format: format };
    const response = await fetch('https://openrouter.ai/api/v1/audio/speech', { method: 'POST', headers, body: JSON.stringify(body) });
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${(await response.text()).slice(0, 500)}`);
    const audio = Buffer.from(await response.arrayBuffer());
    writeFileSync(out, format === 'pcm' ? wav(audio, 24000) : audio);
} else if (command === 'transcribe') {
    // Speech-to-text, used to check that a synthetic voice is intelligible.
    const file = option('file');
    const body = {
        model: option('model', 'elevenlabs/scribe-v2'),
        input_audio: { data: readFileSync(file).toString('base64'), format: file.split('.').pop() },
    };
    const response = await fetch('https://openrouter.ai/api/v1/audio/transcriptions', { method: 'POST', headers, body: JSON.stringify(body) });
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${(await response.text()).slice(0, 500)}`);
    console.log((await response.json()).text);
    process.exit(0);
} else if (command === 'music') {
    // Audio output from chat models is only available as a stream of base64 chunks.
    const body = {
        model: option('model', 'google/lyria-3-clip-preview'),
        modalities: ['text', 'audio'],
        stream: true,
        messages: [{ role: 'user', content: option('prompt') }],
    };
    const response = await fetch('https://openrouter.ai/api/v1/chat/completions', { method: 'POST', headers, body: JSON.stringify(body) });
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${(await response.text()).slice(0, 500)}`);
    const chunks = [];
    let text = '';
    let buffer = '';
    const decoder = new TextDecoder();
    for await (const part of response.body) {
        buffer += decoder.decode(part, { stream: true });
        let end;
        while ((end = buffer.indexOf('\n')) !== -1) {
            const line = buffer.slice(0, end).trim();
            buffer = buffer.slice(end + 1);
            if (!line.startsWith('data:') || line === 'data: [DONE]') continue;
            const delta = JSON.parse(line.slice(5)).choices?.[0]?.delta ?? {};
            if (delta.audio?.data) chunks.push(Buffer.from(delta.audio.data, 'base64'));
            if (delta.content) text += delta.content;
        }
    }
    if (!chunks.length) throw new Error(`No audio in response. Text: ${text.slice(0, 500)}`);
    writeFileSync(out, Buffer.concat(chunks));
    if (text) console.log(text.trim());
} else {
    throw new Error('Command must be speech, transcribe or music');
}
console.log(out);

function wav(pcm, rate) {
    const header = Buffer.alloc(44);
    header.write('RIFF', 0); header.writeUInt32LE(36 + pcm.length, 4); header.write('WAVE', 8);
    header.write('fmt ', 12); header.writeUInt32LE(16, 16); header.writeUInt16LE(1, 20); header.writeUInt16LE(1, 22);
    header.writeUInt32LE(rate, 24); header.writeUInt32LE(rate * 2, 28); header.writeUInt16LE(2, 32); header.writeUInt16LE(16, 34);
    header.write('data', 36); header.writeUInt32LE(pcm.length, 40);
    return Buffer.concat([header, pcm]);
}
