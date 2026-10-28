<?php

namespace Tests\Feature;

use App\Models\PageFeedback;
use App\Services\Analytics\PageFeedbackContextSigner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PageFeedbackEventTest extends TestCase
{
    use RefreshDatabase;

    private PageFeedbackContextSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-28 12:00:00 UTC');
        $this->signer = app(PageFeedbackContextSigner::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_vote_creates_one_row_with_signed_page_facts_and_listing_facts(): void
    {
        $uuid = (string) Str::uuid();
        $envelope = $this->envelope('listing', $uuid, [
            'step' => 'vote',
            'vote' => 'down',
            'facts' => [
                'consumption_kwh' => 18000,
                'pricing_filter' => 'porssisahko,unknown,kiintea',
                'result_count' => 212,
                'postcode_selected' => true,
                'bill_mode' => false,
                'listing_page' => 2,
            ],
        ]);

        $this->postJson(route('analytics.events.store'), $envelope)->assertNoContent();
        $this->postJson(route('analytics.events.store'), $envelope)->assertNoContent();

        $row = PageFeedback::query()->sole();
        $this->assertSame('listing', $row->page_type);
        $this->assertSame('down', $row->vote);
        $this->assertSame('/sahkosopimus', $row->page_path);
        $this->assertSame('mobile', $row->device);
        $this->assertSame(18000, $row->consumption_kwh);
        $this->assertSame('porssisahko,kiintea', $row->pricing_filter);
        $this->assertSame(212, $row->result_count);
        $this->assertTrue($row->postcode_selected);
        $this->assertFalse($row->bill_mode);
        $this->assertSame(2, $row->listing_page);
        $this->assertSame('google', $row->session_source);
        $this->assertTrue($row->occurred_at->equalTo(CarbonImmutable::now('UTC')));
    }

    public function test_listing_facts_are_ignored_on_other_page_types(): void
    {
        $this->vote('contract_detail', $uuid = (string) Str::uuid(), 'up', subject: 'contract-123', facts: ['consumption_kwh' => 5000]);

        $row = PageFeedback::query()->where('event_uuid', $uuid)->sole();
        $this->assertSame('contract-123', $row->subject_id);
        $this->assertNull($row->consumption_kwh);
    }

    public function test_reason_and_goal_fill_the_row_once(): void
    {
        $uuid = (string) Str::uuid();
        $this->vote('listing', $uuid, 'down');

        $this->answer('listing', $uuid, ['step' => 'reason', 'choice' => 'missing_filter', 'position' => 3, 'text' => "  Halusin\r\nvihreän  sähkön  "])->assertNoContent();
        $this->answer('listing', $uuid, ['step' => 'reason', 'choice' => 'hard_to_use', 'position' => 1])->assertNoContent();
        $this->answer('listing', $uuid, ['step' => 'goal', 'choice' => 'find_contract', 'position' => 2])->assertNoContent();

        $row = PageFeedback::query()->sole();
        $this->assertSame('missing_filter', $row->reason_code);
        $this->assertSame(3, $row->reason_position);
        $this->assertSame("Halusin\nvihreän sähkön", $row->reason_text);
        $this->assertSame('find_contract', $row->goal_code);
        $this->assertSame(2, $row->goal_position);
        $this->assertNull($row->goal_text);
    }

    public function test_a_reason_is_stored_only_after_a_down_vote(): void
    {
        $uuid = (string) Str::uuid();
        $this->vote('listing', $uuid, 'up');

        $this->answer('listing', $uuid, ['step' => 'reason', 'choice' => 'hard_to_use', 'position' => 1])->assertNoContent();

        $this->assertNull(PageFeedback::query()->sole()->reason_code);
    }

    public function test_choices_must_belong_to_the_page_type(): void
    {
        $uuid = (string) Str::uuid();
        $this->vote('solar_calculator', $uuid, 'down');

        // A contract reason is never offered on the solar calculator.
        $this->answer('solar_calculator', $uuid, ['step' => 'reason', 'choice' => 'no_suitable_contract', 'position' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('choice');
        $this->answer('solar_calculator', $uuid, ['step' => 'goal', 'choice' => 'check_bill', 'position' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('choice');
        $this->answer('solar_calculator', $uuid, ['step' => 'reason', 'choice' => 'address_not_found', 'position' => 9])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('position');
        $this->answer('solar_calculator', $uuid, ['step' => 'reason'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('choice');

        $this->assertNull(PageFeedback::query()->sole()->reason_code);
    }

    public function test_follow_up_after_the_window_or_on_another_page_type_is_a_no_op(): void
    {
        $uuid = (string) Str::uuid();
        $this->vote('listing', $uuid, 'down');

        $this->answer('home', $uuid, ['step' => 'goal', 'choice' => 'find_contract', 'position' => 1])->assertNoContent();

        CarbonImmutable::setTestNow('2026-10-28 14:01:00 UTC');
        $this->answer('listing', $uuid, ['step' => 'reason', 'choice' => 'hard_to_use', 'position' => 1])->assertNoContent();

        $row = PageFeedback::query()->sole();
        $this->assertNull($row->goal_code);
        $this->assertNull($row->reason_code);
    }

    public function test_not_found_page_stores_a_goal_without_a_vote(): void
    {
        $uuid = (string) Str::uuid();

        $this->answer('not_found', $uuid, ['step' => 'vote', 'vote' => 'up'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('step');
        $this->answer('not_found', $uuid, ['step' => 'goal', 'text' => 'Etsin vanhaa sopimusta'])->assertNoContent();

        $row = PageFeedback::query()->sole();
        $this->assertNull($row->vote);
        $this->assertNull($row->goal_code);
        $this->assertSame('Etsin vanhaa sopimusta', $row->goal_text);
    }

    public function test_text_longer_than_the_limit_and_bad_contexts_are_rejected(): void
    {
        $uuid = (string) Str::uuid();
        $this->vote('listing', $uuid, 'down');

        $this->answer('listing', $uuid, ['step' => 'reason', 'text' => str_repeat('a', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');

        $envelope = $this->envelope('listing', (string) Str::uuid(), ['step' => 'vote', 'vote' => 'up']);
        $envelope['context'] .= 'x';
        $this->postJson(route('analytics.events.store'), $envelope)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('context');

        $expired = $this->envelope('listing', (string) Str::uuid(), ['step' => 'vote', 'vote' => 'up']);
        $expired['context'] = $this->signer->sign('listing', null, CarbonImmutable::now()->subHours(97));
        $this->postJson(route('analytics.events.store'), $expired)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('context');

        $this->assertSame(1, PageFeedback::query()->count());
    }

    public function test_purge_command_deletes_only_old_free_text(): void
    {
        $this->vote('listing', $old = (string) Str::uuid(), 'down');
        $this->answer('listing', $old, ['step' => 'reason', 'choice' => 'hard_to_use', 'position' => 1, 'text' => 'Vanha teksti'])->assertNoContent();

        CarbonImmutable::setTestNow('2027-11-01 12:00:00 UTC');
        $this->vote('listing', $new = (string) Str::uuid(), 'down');
        $this->answer('listing', $new, ['step' => 'reason', 'text' => 'Uusi teksti'])->assertNoContent();

        $this->artisan('feedback:purge-text')->assertSuccessful();

        $oldRow = PageFeedback::query()->where('event_uuid', $old)->sole();
        $this->assertNull($oldRow->reason_text);
        $this->assertSame('hard_to_use', $oldRow->reason_code);
        $this->assertSame('Uusi teksti', PageFeedback::query()->where('event_uuid', $new)->sole()->reason_text);
    }

    private function vote(string $pageType, string $uuid, string $vote, ?string $subject = null, ?array $facts = null): void
    {
        $this->postJson(route('analytics.events.store'), $this->envelope($pageType, $uuid, [
            'step' => 'vote',
            'vote' => $vote,
            'facts' => $facts,
        ], $subject))->assertNoContent();
    }

    private function answer(string $pageType, string $uuid, array $fields): TestResponse
    {
        return $this->postJson(route('analytics.events.store'), $this->envelope($pageType, $uuid, $fields));
    }

    private function envelope(string $pageType, string $uuid, array $fields, ?string $subject = null): array
    {
        return [
            'event_name' => 'page_feedback',
            'event_uuid' => $uuid,
            'context' => $this->signer->sign($pageType, $subject),
            'attribution' => [
                'source' => 'Google',
                'medium' => 'organic',
                'campaign' => null,
                'landing_path' => '/sahkosopimus',
            ],
            'page_path' => '/sahkosopimus?hintatyyppi=kiintea',
            'device' => 'mobile',
            ...$fields,
        ];
    }
}
