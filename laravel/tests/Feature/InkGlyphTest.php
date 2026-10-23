<?php

namespace Tests\Feature;

use Tests\TestCase;

class InkGlyphTest extends TestCase
{
    public function test_open_ended_duration_glyph_is_an_envelope_not_the_fixed_padlock(): void
    {
        $openEnded = (string) $this->blade('<x-ink.glyph bucket="toistaiseksi" size="30" />');
        $fixed = (string) $this->blade('<x-ink.glyph bucket="kiintea" size="30" />');

        // The envelope flap and the dashed open end identify the open-ended glyph.
        $this->assertStringContainsString('M24.6 12.2 L32.6 17.9 L40.6 11.9', $openEnded);
        $this->assertStringContainsString('stroke-dasharray="2.6 2.4"', $openEnded);
        $this->assertStringContainsString('var(--color-ink-muted)', $openEnded);
        $this->assertStringNotContainsString('<circle', $openEnded);
        $this->assertStringContainsString('<circle', $fixed);
    }
}
