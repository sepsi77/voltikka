<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FormInputBlurPolicyTest extends TestCase
{
    /**
     * Ordinary editable values sync on blur. Marked search inputs use debounce
     * so result lists update while the visitor types.
     */
    public function test_editable_livewire_fields_use_the_required_update_boundary(): void
    {
        $violations = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = file_get_contents($file->getPathname());

            preg_match_all('/<(input|textarea)\b[^>]*>/is', $contents, $tags, PREG_OFFSET_CAPTURE);

            foreach ($tags[0] as [$tag, $offset]) {
                if (! $this->isEditableField($tag)) {
                    continue;
                }

                if (preg_match('/\bwire:model((?:\.[a-zA-Z0-9_-]+)*)\s*=/', $tag, $model) === 1) {
                    $isSearch = str_contains($tag, 'data-search-input')
                        || preg_match('/\btype\s*=\s*["\']search["\']/i', $tag) === 1;
                    $validBinding = $isSearch
                        ? preg_match('/^\.live\.debounce\.\d+ms$/', $model[1]) === 1
                        : $model[1] === '.blur';

                    if (! $validBinding) {
                        $expected = $isSearch ? 'wire:model.live.debounce.Nms' : 'wire:model.blur';
                        $violations[] = $this->violation($file, $contents, $offset, $model[0], $expected);
                    }
                }

                if (preg_match('/\bwire:(?:input|change)\b/', $tag, $event) === 1) {
                    $violations[] = $this->violation($file, $contents, $offset, $event[0]);
                }

                if (preg_match('/(?:x-on:|@)(?:input|change)[^=]*=["\'][^"\']*\$wire\b/i', $tag, $event) === 1) {
                    $violations[] = $this->violation($file, $contents, $offset, $event[0]);
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_numeric_fields_define_a_non_negative_minimum(): void
    {
        $violations = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = file_get_contents($file->getPathname());

            preg_match_all('/<input\b[^>]*>/is', $contents, $tags, PREG_OFFSET_CAPTURE);

            foreach ($tags[0] as [$tag, $offset]) {
                if (preg_match('/\btype\s*=\s*["\'](?:number|range)["\']/i', $tag) !== 1
                    || str_contains($tag, 'data-allow-negative')) {
                    continue;
                }

                if (preg_match('/\bmin\s*=\s*["\']([^"\']+)["\']/i', $tag, $minimum) !== 1) {
                    $violations[] = $this->violation($file, $contents, $offset, 'no min attribute', 'a non-negative min');

                    continue;
                }

                if (is_numeric($minimum[1]) && (float) $minimum[1] < 0) {
                    $violations[] = $this->violation($file, $contents, $offset, "min={$minimum[1]}", 'a non-negative min');
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_solar_search_is_debounced_and_numeric_fields_use_blur(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/solar-calculator.blade.php');

        $this->assertStringContainsString('wire:model.live.debounce.300ms="addressQuery"', $view);
        $this->assertStringContainsString('data-search-input', $view);
        $this->assertStringContainsString('wire:model.blur="systemKwp"', $view);
        $this->assertStringContainsString('wire:model.blur="manualPrice"', $view);
        $this->assertStringNotContainsString('wire:model.live.debounce.300ms="manualPrice"', $view);
    }

    /** @return list<SplFileInfo> */
    private function bladeFiles(): array
    {
        $directory = dirname(__DIR__, 2).'/resources/views';
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function isEditableField(string $tag): bool
    {
        if (str_starts_with(strtolower($tag), '<textarea')) {
            return true;
        }

        if (preg_match('/\btype\s*=\s*["\']([^"\']+)["\']/i', $tag, $type) !== 1) {
            return true;
        }

        return in_array(strtolower($type[1]), [
            'text',
            'number',
            'email',
            'password',
            'search',
            'tel',
            'url',
            'date',
            'month',
            'week',
            'time',
            'datetime-local',
        ], true);
    }

    private function violation(
        SplFileInfo $file,
        string $contents,
        int $offset,
        string $binding,
        string $expected = 'wire:model.blur',
    ): string {
        $root = dirname(__DIR__, 2);
        $path = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
        $line = substr_count(substr($contents, 0, $offset), "\n") + 1;

        return "{$path}:{$line} editable field uses {$binding}; use {$expected}";
    }
}
