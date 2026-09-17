import { constants, copyFileSync, lstatSync, mkdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// Explicit names keep documentation and other source files out of public assets.
const retainedAssets = ['app-BE-AUgaZ.css'];
let buildConfig;

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/contract-price-statistics.js',
            ],
            refresh: true,
        }),
        {
            name: 'retained-build-assets',
            apply: 'build',
            configResolved(config) {
                buildConfig = config;
            },
            closeBundle() {
                const output = resolve(buildConfig.root, buildConfig.build.outDir, 'assets');
                mkdirSync(output, { recursive: true });

                for (const name of retainedAssets) {
                    if (!/^[A-Za-z0-9_-]+\.(css|js)$/.test(name)) {
                        throw new Error(`Unsafe retained asset name: ${name}`);
                    }

                    const source = resolve(buildConfig.root, 'resources/retained-build-assets', name);
                    const target = resolve(output, name);
                    if (!lstatSync(source).isFile()) {
                        throw new Error(`Retained asset must be a regular file: ${name}`);
                    }

                    try {
                        copyFileSync(source, target, constants.COPYFILE_EXCL);
                    } catch (error) {
                        if (error.code !== 'EEXIST') throw error;
                        if (!lstatSync(target).isFile() || !readFileSync(source).equals(readFileSync(target))) {
                            throw new Error(`Retained asset collision: ${name}`);
                        }
                    }
                }
            },
        },
    ],
});
