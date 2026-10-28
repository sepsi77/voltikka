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
    // The CSS and JS served by production before the page-feedback release (fetched 2026-10-28).
    const feedbackRelease = [
        ['app-CSDxisPt.css', 308870, '73f1e4adcc62fa0e0acae0d69d0d0ee5819f0eca49ebf2ecc171d59b41e2a3ba'],
        ['app-Pr_ebGQi.js', 42427, '80ff016ecbb8e826212bf9885b0d3626fef63bd9f66873a64e0e1bba71d926e0'],
    ].map(([file, size, hash]) => ({
        file,
        size,
        hash,
        bytes: readFileSync(new URL(`../../resources/retained-build-assets/${file}`, import.meta.url)),
    }));

    try {
        assert.equal(bytes.length, 110597);
        assert.equal(createHash('sha256').update(bytes).digest('hex'),
            'e27279f29da59bdfc18093c71ee05e6d08d237bd673e9b618a58f4f9e5335528');
        assert.equal(laterBytes.length, 308432);
        assert.equal(createHash('sha256').update(laterBytes).digest('hex'),
            '762e60df9e096b2428b6c6608bdfd940aa7ff11c35c9c6f00510635f362b1f08');
        for (const asset of feedbackRelease) {
            assert.equal(asset.bytes.length, asset.size, asset.file);
            assert.equal(createHash('sha256').update(asset.bytes).digest('hex'), asset.hash, asset.file);
        }
        mkdirSync(source, { recursive: true });
        mkdirSync(output, { recursive: true });
        writeFileSync(join(source, name), bytes);
        writeFileSync(join(source, later), laterBytes);
        for (const asset of feedbackRelease) {
            writeFileSync(join(source, asset.file), asset.bytes);
        }
        writeFileSync(join(source, 'README.md'), 'Do not publish this file.');
        const manifest = '{"current":{"file":"assets/current.css"}}';
        writeFileSync(join(output, 'manifest.json'), manifest);
        plugin.configResolved({ root, build: { outDir: 'public/build' } });
        plugin.closeBundle();
        plugin.closeBundle();
        assert.deepEqual(readFileSync(target), bytes);
        assert.deepEqual(readFileSync(join(output, 'assets', later)), laterBytes);
        for (const asset of feedbackRelease) {
            assert.deepEqual(readFileSync(join(output, 'assets', asset.file)), asset.bytes);
        }
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
