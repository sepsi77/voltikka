// Renders an HTML scene to MP4, one frame at a time.
//
//   node render.mjs <scene.html> [--duration 3] [--fps 30] [--size 1920x1080]
//                   [--out file.mp4] [--stills 0,1.5,3]
//
// Output goes to out/ next to the scene (the project folder), unless --out is given.
//
// Time is virtual, so every render is the same. For each frame the renderer
// pauses all CSS animations and transitions and seeks them to time t, then
// calls window.renderFrame(t) when the scene defines it (for JS-driven parts).
// --stills writes only PNG files at those times, for a quick visual check.

import { chromium } from 'playwright';
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { basename, dirname, extname, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const args = process.argv.slice(2);
const scene = args.find((a) => !a.startsWith('--') && !isOptionValue(a));
if (!scene) {
    console.error('Usage: node render.mjs <scene.html> [--duration s] [--fps n] [--size WxH] [--out file] [--stills t1,t2]');
    process.exit(1);
}

const duration = Number(option('duration', 3));
const fps = Number(option('fps', 30));
const [width, height] = option('size', '1920x1080').split('x').map(Number);
const name = basename(scene, extname(scene)) === 'index' ? basename(dirname(resolve(scene))) : basename(scene, extname(scene));
const out = resolve(option('out', resolve(dirname(scene), 'out', `${name}.mp4`)));
const stills = option('stills', null)?.split(',').map(Number);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 1 });
await page.goto(pathToFileURL(resolve(scene)).href, { waitUntil: 'networkidle' });
await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all([...document.images].map((img) => img.decode().catch(() => {})));
});

const seek = (t) => page.evaluate((t) => {
    for (const animation of document.getAnimations()) {
        animation.pause();
        animation.currentTime = t * 1000;
    }
    window.renderFrame?.(t);
}, t);

mkdirSync(dirname(out), { recursive: true });

if (stills) {
    for (const t of stills) {
        await seek(t);
        const file = out.replace(/\.mp4$/, `-${t.toFixed(2)}s.png`);
        writeFileSync(file, await page.screenshot({ type: 'png' }));
        console.log(file);
    }
} else {
    const ffmpeg = spawn('ffmpeg', [
        '-y', '-loglevel', 'error',
        '-f', 'image2pipe', '-framerate', String(fps), '-i', '-',
        '-c:v', 'libx264', '-preset', 'slow', '-crf', '16', '-pix_fmt', 'yuv420p',
        '-movflags', '+faststart', out,
    ], { stdio: ['pipe', 'inherit', 'inherit'] });
    const done = new Promise((ok, fail) => ffmpeg.on('close', (code) => (code === 0 ? ok() : fail(new Error(`ffmpeg exit ${code}`)))));

    const frames = Math.round(duration * fps);
    const started = Date.now();
    for (let frame = 0; frame < frames; frame++) {
        await seek(frame / fps);
        const png = await page.screenshot({ type: 'png' });
        if (!ffmpeg.stdin.write(png)) await new Promise((ok) => ffmpeg.stdin.once('drain', ok));
    }
    ffmpeg.stdin.end();
    await done;
    console.log(`${out} (${frames} frames in ${((Date.now() - started) / 1000).toFixed(1)} s)`);
}

await browser.close();

function option(key, fallback) {
    const i = args.indexOf(`--${key}`);
    return i === -1 ? fallback : args[i + 1];
}

function isOptionValue(arg) {
    const i = args.indexOf(arg);
    return i > 0 && args[i - 1].startsWith('--');
}
