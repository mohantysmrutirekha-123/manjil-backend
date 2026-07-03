<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_converting_selected_opportunity_items_does_not_convert_same_item_from_another_opportunity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $principal = User::factory()->create();

        $item = Item::create([
            'name' => 'Industrial Valve',
            'mat_code' => 'MAT-1001',
            'part_model_no' => 'VALVE-2026',
            'description' => 'Industrial stainless steel valve',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $firstOpportunityItem = $this->createOpportunityItem(
            'OPP-003',
            $admin,
            $customer,
            $principal,
            $item
        );

        $secondOpportunityItem = $this->createOpportunityItem(
            'OPP-004',
            $admin,
            $customer,
            $principal,
            $item
        );

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/opportunity/convert-to-enquiry', [
            'opportunity_id' => $firstOpportunityItem->id,
            'opportunity_item_ids' => [$firstOpportunityItem->id],
            'enquiry_type' => 'Vendor',
            'concerned_person' => 'Test User',
            'concerned_area' => 'Bhubaneswar',
            'status' => 'Pending',
            'date' => '2026-07-01',
        ]);

        $response->assertOk();

        $firstOpportunityItem->refresh();
        $secondOpportunityItem->refresh();

        $this->assertNotNull($firstOpportunityItem->converted_inquiry_id);
        $this->assertSame('Quotation Placed', $firstOpportunityItem->item_status);

        $this->assertNull($secondOpportunityItem->converted_inquiry_id);
        $this->assertSame('Pending', $secondOpportunityItem->item_status);

        $this->assertDatabaseHas('inquiries', [
            'source_opportunity_item_id' => $firstOpportunityItem->id,
            'item_id' => $item->id,
        ]);

        $this->assertDatabaseMissing('inquiries', [
            'source_opportunity_item_id' => $secondOpportunityItem->id,
        ]);
    }

    private function createOpportunityItem(
        string $code,
        User $admin,
        User $customer,
        User $principal,
        Item $item
    ): Opportunity {
        $opportunity = Opportunity::create([
            'opportunity_code' => $code,
            'opportunity_date' => '2026-07-01',
            'user_id' => $admin->id,
            'customer_id' => $customer->id,
            'principal_id' => $principal->id,
            'quotation_ref' => 'QTN-2026',
            'details' => 'Opportunity created for customer discussion',
            'item_id' => $item->id,
            'purchase_group' => 'Mechanical',
            'location' => 'Bhubaneswar',
            'quantity' => 20,
            'item_date' => '2026-07-01',
            'item_status' => 'Pending',
            'status' => 'Open',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $opportunity->update(['parent_id' => $opportunity->id]);

        return $opportunity;
    }
}
