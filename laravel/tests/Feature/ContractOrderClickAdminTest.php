<?php

namespace Tests\Feature;

use App\Filament\Resources\ContractOrderClicks\ContractOrderClickResource;
use App\Filament\Resources\ContractOrderClicks\Pages\ListContractOrderClicks;
use App\Models\ContractOrderClick;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ContractOrderClickAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_unauthenticated_and_non_admin_users_cannot_access_the_panel(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $normalUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($normalUser)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_user_can_access_panel_and_click_resource(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)
            ->get(ContractOrderClickResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Sopimustilaukseen siirtymiset');
    }

    public function test_panel_has_no_public_registration(): void
    {
        $this->get('/admin/register')->assertNotFound();
    }

    public function test_resource_has_no_create_edit_delete_or_bulk_mutation_paths(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $click = $this->click();
        $this->actingAs($admin);

        $this->assertSame(['index'], array_keys(ContractOrderClickResource::getPages()));
        $this->assertFalse(ContractOrderClickResource::canCreate());
        $this->assertFalse(ContractOrderClickResource::canEdit($click));
        $this->assertFalse(ContractOrderClickResource::canDelete($click));
        $this->assertFalse(ContractOrderClickResource::canDeleteAny());
        $this->assertFalse(ContractOrderClickResource::canForceDelete($click));
        $this->assertFalse(ContractOrderClickResource::canForceDeleteAny());
        $this->assertFalse(ContractOrderClickResource::canRestore($click));
        $this->assertFalse(ContractOrderClickResource::canRestoreAny());
        $this->assertFalse(ContractOrderClickResource::canReplicate($click));
        $this->assertFalse(ContractOrderClickResource::canReorder());

        $component = Livewire::test(ListContractOrderClicks::class)
            ->assertActionDoesNotExist('create')
            ->assertActionExists('exportCsv')
            ->assertActionHasUrl('exportCsv', route('filament.admin.contract-order-clicks.export'))
            ->assertTableActionDoesNotExist('edit', record: $click)
            ->assertTableActionDoesNotExist('delete', record: $click);

        $this->assertSame([], $component->instance()->getTable()->getRecordActions());
        $this->assertSame([], $component->instance()->getTable()->getToolbarActions());
        $this->get('/admin/contract-order-clicks/create')->assertNotFound();
        $this->get('/admin/contract-order-clicks/'.$click->getKey().'/edit')->assertNotFound();
    }

    public function test_only_admin_users_can_download_the_csv_export(): void
    {
        $exportUrl = route('filament.admin.contract-order-clicks.export');

        $this->get($exportUrl)->assertRedirect('/admin/login');

        $normalUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($normalUser)
            ->get($exportUrl)
            ->assertForbidden();
    }

    public function test_csv_export_contains_every_column_and_every_click(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $clicks = collect([
            $this->click([
                'event_uuid' => '11111111-1111-4111-8111-111111111111',
                'contract_name' => 'Vakaa Tuuli',
                'session_campaign' => null,
            ]),
            $this->click([
                'event_uuid' => '22222222-2222-4222-8222-222222222222',
                'contract_name' => 'Yösähkö',
                'session_campaign' => 'syksy',
            ]),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('filament.admin.contract-order-clicks.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'max-age=0, no-store, private');

        $this->assertStringContainsString(
            'attachment; filename=voltikka-contract-order-clicks-',
            (string) $response->headers->get('content-disposition'),
        );

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        $columns = Schema::getColumnListing('contract_order_clicks');
        $this->assertSame($columns, $rows[0]);
        $this->assertCount($clicks->count() + 1, $rows);

        $exportedByUuid = collect(array_slice($rows, 1))
            ->map(fn (array $row): array => array_combine($columns, $row))
            ->keyBy('event_uuid');

        foreach ($clicks as $click) {
            $exported = $exportedByUuid->get($click->event_uuid);
            $this->assertNotNull($exported);

            foreach ($columns as $column) {
                $rawValue = $click->fresh()->getRawOriginal($column);
                $this->assertSame($rawValue === null ? '' : (string) $rawValue, $exported[$column]);
            }
        }
    }

    public function test_table_is_newest_first_and_paginates_with_a_result_count(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $clicks = collect(range(1, 30))->map(fn (int $day) => $this->click([
            'occurred_at' => now()->startOfDay()->addMinutes($day),
            'contract_id' => 'contract-'.$day,
            'contract_name' => 'Sopimus '.$day,
        ]));

        $newestPage = $clicks->reverse()->take(25)->values();
        $oldestPage = $clicks->take(5);

        Livewire::test(ListContractOrderClicks::class)
            ->assertCountTableRecords(30)
            ->assertCanSeeTableRecords($newestPage, inOrder: true)
            ->assertCanNotSeeTableRecords($oldestPage);
    }

    public function test_company_and_contract_search_work(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $wanted = $this->click([
            'company_name' => 'Aurora Energia',
            'contract_name' => 'Vakaa Tuuli',
            'contract_id' => 'aurora-vakaa',
        ]);
        $other = $this->click([
            'company_name' => 'Muu Yhtiö',
            'contract_name' => 'Perussähkö',
            'contract_id' => 'other-contract',
        ]);

        Livewire::test(ListContractOrderClicks::class)
            ->searchTable('Aurora')
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListContractOrderClicks::class)
            ->searchTable('aurora-vakaa')
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_all_native_filters_limit_the_result_set(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $wanted = $this->click([
            'occurred_at' => '2026-08-05 10:00:00',
            'company_name' => 'Aurora Energia',
            'contract_name' => 'Vakaa Tuuli',
            'session_source' => 'google',
            'session_medium' => 'organic',
            'session_campaign' => 'summer',
            'cta_location' => 'sticky',
        ]);
        $other = $this->click([
            'occurred_at' => '2026-07-01 10:00:00',
            'company_name' => 'Muu Yhtiö',
            'contract_name' => 'Perussähkö',
            'session_source' => 'newsletter',
            'session_medium' => 'email',
            'session_campaign' => 'winter',
            'cta_location' => 'hero',
        ]);

        $filters = [
            ['occurred_at', ['from' => '2026-08-01', 'until' => '2026-08-31']],
            ['company_name', ['value' => 'Aurora Energia']],
            ['contract_name', ['value' => 'Vakaa Tuuli']],
            ['session_source', ['value' => 'google']],
            ['session_medium', ['value' => 'organic']],
            ['session_campaign', ['value' => 'summer']],
            ['cta_location', 'sticky'],
        ];

        foreach ($filters as [$filter, $value]) {
            Livewire::test(ListContractOrderClicks::class)
                ->filterTable($filter, $value)
                ->assertCountTableRecords(1)
                ->assertCanSeeTableRecords([$wanted])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function click(array $overrides = []): ContractOrderClick
    {
        return ContractOrderClick::query()->create(array_replace([
            'event_uuid' => (string) Str::uuid(),
            'occurred_at' => now(),
            'contract_id' => 'contract-1',
            'contract_name' => 'Vakaa 12 kk',
            'company_name' => 'Test Energia Oy',
            'annual_price_eur' => 650.50,
            'consumption_kwh' => 5000,
            'price_rank' => 4,
            'rank_total' => 300,
            'rank_consumption_kwh' => 5000,
            'is_estimate' => true,
            'pricing_basis' => 'canonical',
            'cta_location' => 'hero',
            'session_source' => 'direct',
            'session_medium' => '(none)',
            'session_campaign' => null,
            'landing_path' => '/',
            'page_path' => '/sahkosopimus/sopimus/contract-1',
        ], $overrides));
    }
}
