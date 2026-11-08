// Paths of one review edition. Each edition keeps its own video sources and outputs:
//
//   market-review/editions/<month>/video/   script.mjs, scene HTML, data.js, timeline/ (git)
//   market-review/editions/<month>/work/    voice takes, voice tracks, renders, stills, archive (not in git)
//   market-review/editions/<month>/final/   the files to upload; text files in git, media not
//
// The engine (this folder) is shared by all editions. Tools take the edition as their first argument.
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const ENGINE = dirname(fileURLToPath(import.meta.url));
export const GENERATE_AUDIO = resolve(ENGINE, '../generate-audio.mjs');

export async function edition(month) {
    if (!/^\d{4}-\d{2}$/.test(month ?? '')) throw new Error(`First argument must be the edition month (YYYY-MM), got: ${month}`);
    const root = resolve(ENGINE, '../../market-review/editions', month);
    const video = `${root}/video`;
    if (!existsSync(`${video}/script.mjs`)) throw new Error(`No ${video}/script.mjs`);
    const script = await import(pathToFileURL(`${video}/script.mjs`).href);
    const work = `${root}/work`, final = `${root}/final`;
    return {
        month, root, video, work, final, script,
        variant(name) {
            const v = script.VARIANTS[name];
            if (!v) throw new Error(`Unknown variant: ${name}`);
            return v;
        },
        // Voice takes of a variant: work/audio/vo/<voice>/. MAI: voice name (+ speed); other models: `vo`.
        voDir: (v) => `${work}/audio/vo/${v.vo ?? v.voice.split(':')[0].replace('fi-FI-', '').toLowerCase() + (v.speed ? '-' + v.speed : '')}`,
        // Output file base: final/[<sub>/]voltikka-markkinakatsaus-<month>-<variant> for a published variant
        // (`final: true` or a subfolder name), else work/archive/.
        out(name) {
            const v = script.VARIANTS[name];
            const dir = v.final ? (v.final === true ? final : `${final}/${v.final}`) : `${work}/archive`;
            return `${dir}/voltikka-markkinakatsaus-${month}-${name}`;
        },
        timeline: (name) => `${video}/timeline/${name}.js`,
        music: (v) => resolve(ENGINE, v.music),
    };
}

// Reads timeline/<variant>.js (window.TL) into a plain object.
export async function readTimeline(file) {
    const { readFileSync } = await import('node:fs');
    const window = {};
    new Function('window', readFileSync(file, 'utf8'))(window);
    return window.TL;
}
