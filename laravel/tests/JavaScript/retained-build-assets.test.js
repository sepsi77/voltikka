import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import config from '../../vite.config.js';

test('retained build asset keeps exact bytes and rejects collisions and symlinks', () => {
    const root = mkdtempSync(join(tmpdir(), 'voltikka-retained-assets-'));
    const name = 'app-BE-AUgaZ.css';
    const plugin = config.plugins.find((plugin) => plugin.name === 'retained-build-assets');
    const source = join(root, 'resources/retained-build-assets');
    const output = join(root, 'public/build');
    const target = join(output, 'assets', name);
    const bytes = readFileSync(new URL(`../../resources/retained-build-assets/${name}`, import.meta.url));
    // The CSS of deploy-000001-5ff86c292587, kept for edge-cached HTML of that release.
    const later = 'app-DjVQ2Ja9.css';
    const laterBytes = readFileSync(new URL(`../../resources/retained-build-assets/${later}`, import.meta.url));

    try {
        assert.equal(bytes.length, 110597);
        assert.equal(createHash('sha256').update(bytes).digest('hex'),
            'e27279f29da59bdfc18093c71ee05e6d08d237bd673e9b618a58f4f9e5335528');
        assert.equal(laterBytes.length, 308432);
        assert.equal(createHash('sha256').update(laterBytes).digest('hex'),
            '762e60df9e096b2428b6c6608bdfd940aa7ff11c35c9c6f00510635f362b1f08');
        mkdirSync(source, { recursive: true });
        mkdirSync(output, { recursive: true });
        writeFileSync(join(source, name), bytes);
        writeFileSync(join(source, later), laterBytes);
        writeFileSync(join(source, 'README.md'), 'Do not publish this file.');
        const manifest = '{"current":{"file":"assets/current.css"}}';
        writeFileSync(join(output, 'manifest.json'), manifest);
        plugin.configResolved({ root, build: { outDir: 'public/build' } });
        plugin.closeBundle();
        plugin.closeBundle();
        assert.deepEqual(readFileSync(target), bytes);
        assert.deepEqual(readFileSync(join(output, 'assets', later)), laterBytes);
        assert.equal(existsSync(join(output, 'assets/README.md')), false);
        assert.equal(readFileSync(join(output, 'manifest.json'), 'utf8'), manifest);

        writeFileSync(target, 'different bytes');
        assert.throws(() => plugin.closeBundle(), /Retained asset collision/);
        assert.equal(readFileSync(target, 'utf8'), 'different bytes');

        rmSync(target);
        symlinkSync(join(source, name), target);
        assert.throws(() => plugin.closeBundle(), /Retained asset collision/);
        rmSync(target);
        copyFileSync(join(source, name), join(root, 'original.css'));
        rmSync(join(source, name));
        symlinkSync(join(root, 'original.css'), join(source, name));
        assert.throws(() => plugin.closeBundle(), /must be a regular file/);
    } finally {
        rmSync(root, { recursive: true, force: true });
    }
});
