<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Inquiry;
use App\Models\Item;
use App\Models\User;
use App\Models\MaterialShipmentBatch;
use App\Models\PaymentTransaction;
use App\Models\PrincipalFollowup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WordDocumentSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        echo "=== SEEDING FULL LIFECYCLE WORD DOCUMENT TRANSACTIONS ===\n";

        // Admin User
        $admin = User::firstOrCreate(
            ['email' => 'admin@test.com'],
            ['name' => 'admin', 'password' => bcrypt('admin123'), 'role' => 'admin']
        );

        // Customers
        $tataSteel  = User::firstOrCreate(['name' => 'Tata Steel'], ['email' => 'tatasteel@test.com', 'role' => 'customer']);
        $navaBharat = User::firstOrCreate(['name' => 'Nava Bharat'], ['email' => 'navabharat@test.com', 'role' => 'customer']);
        $birla      = User::firstOrCreate(['name' => 'UAIL / Aditya Birla'], ['email' => 'birla@test.com', 'role' => 'customer']);
        $jspl       = User::firstOrCreate(['name' => 'JSPL'], ['email' => 'jspl@test.com', 'role' => 'customer']);
        $vedanta    = User::firstOrCreate(['name' => 'Vedanta Lanjigarh'], ['email' => 'vedanta@test.com', 'role' => 'customer']);
        $ntpc       = User::firstOrCreate(['name' => 'NTPC'], ['email' => 'ntpc@test.com', 'role' => 'customer']);
        $iffco      = User::firstOrCreate(['name' => 'IFFCO'], ['email' => 'iffco@test.com', 'role' => 'customer']);

        // Vendors / Principals
        $bray = User::firstOrCreate(['name' => 'BRAY'], ['email' => 'bray@test.com', 'role' => 'vendor']);
        $nord = User::firstOrCreate(['name' => 'NORD'], ['email' => 'nord@test.com', 'role' => 'vendor']);
        $asco = User::firstOrCreate(['name' => 'ASCO'], ['email' => 'asco@test.com', 'role' => 'vendor']);

        // Items
        $splValve = Item::firstOrCreate(['name' => 'SPL Valve'], ['mat_code' => 'VALVE-SPL-01', 'description' => 'Special Control Valve']);
        $bfValve  = Item::firstOrCreate(['name' => 'Butterfly Valve'], ['mat_code' => 'BFV-GEN-01', 'description' => 'Standard Industrial Butterfly Valve']);
        $bfSpares = Item::firstOrCreate(['name' => 'Butterfly Valve Spares Kit'], ['mat_code' => 'BFV-SPR-01', 'description' => 'Maintenance Spares Kit']);
        $seatRing = Item::firstOrCreate(['name' => 'Seat Ring Spares'], ['mat_code' => 'BFV-SR-02', 'description' => 'EPDM / PTFE Seat Ring']);
        $stem     = Item::firstOrCreate(['name' => 'Stem Spares'], ['mat_code' => 'STM-001', 'description' => 'Stainless Steel Valve Stem']);
        $disc     = Item::firstOrCreate(['name' => 'Disc Spares'], ['mat_code' => 'DSC-001', 'description' => 'Stainless Steel Valve Disc']);
        $gearbox  = Item::firstOrCreate(['name' => 'Heavy Duty Gearbox'], ['mat_code' => 'GBX-HD-01', 'description' => 'Industrial Helical Gearbox']);
        $solenoid = Item::firstOrCreate(['name' => 'Solenoid Valve ASCO'], ['mat_code' => 'SOL-ASCO-01', 'description' => 'Pneumatic 3/2 Solenoid Valve']);

        // Clear existing demo records to ensure clean idempotency
        Inquiry::whereIn('inquiry_code', ['ME-159', 'ME-190', 'ME-209', 'ME-241', 'ME-267', 'ME-285', 'ME-289', 'ME-301', 'ME-302', 'ME-303'])->delete();
        Inquiry::whereIn('me_ref', ['ME-159', 'ME-190', 'ME-209', 'ME-241', 'ME-267', 'ME-285', 'ME-289', 'ME-301', 'ME-302', 'ME-303'])->delete();

        // =====================================================================
        // SAMPLE 1: ME-190 — Tata Steel (Customer PO -> Principal PO -> PI -> SI -> Closed)
        // =====================================================================
        echo "Seeding Sample 1: ME-190 (Tata Steel)...\n";
        $p1 = Inquiry::create([
            'inquiry_code'                => 'ME-190',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $tataSteel->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $splValve->id,
            'product_details'             => 'SPL Valve, Size: 150mm ANSI 300#',
            'quantity'                    => 2,
            'price'                       => 587300.00,
            'basic_amount'                => 1174600.00,
            'total_selling_value'         => 1386028.00,
            'gst_percent'                 => 18,
            'customer_po_no'              => '2100968509/A01',
            'customer_po_date'            => '2025-11-14',
            'customer_due_date'           => '2026-06-16',
            'delivery_location'           => 'Tata Steel Jamshedpur Works',
            'customer_payment_terms'      => '30 days net from invoice submission',
            'ld_terms'                    => '0.5% per week up to max 5%',
            'me_ref'                      => 'ME-190',
            'po_principal_no'             => '25191',
            'po_date'                     => '2025-11-22',
            'total_qty_ordered_principal' => 2,
            'unit_purchase_price'         => 482060.00,
            'po_amount_principal'         => 964120.00,
            'total_with_gst'              => 1137661.60,
            'principal_co_no'             => 'CO-BRAY-25191',
            'principal_co_date'           => '2025-12-05',
            'promise_date'                => '2026-04-30',
            'original_promise_date'       => '2026-04-30',
            'status'                      => 'Completed',
            'order_closure_status'        => 'Closed',
            'closure_checklist_snapshot'  => [
                'checks' => [
                    'material_dispatched' => ['status' => true, 'label' => '100% Material Dispatched & Delivered'],
                    'sales_invoiced'      => ['status' => true, 'label' => '100% Sales Invoiced'],
                    'portal_submitted'    => ['status' => true, 'label' => 'Customer Portal Submission Accepted'],
                    'customer_paid'       => ['status' => true, 'label' => 'Customer Payment Received in Full'],
                    'principal_paid'      => ['status' => true, 'label' => 'Principal Payment Settled'],
                ],
                'can_close'      => true,
                'closed_by_name' => 'System Admin',
                'closed_remarks' => 'All 5-point verification criteria fulfilled and verified.',
            ],
            'closed_at'                   => '2026-06-15 14:00:00',
            'closed_by'                   => $admin->id,
            'created_by'                  => $admin->id,
        ]);
        $p1->update(['parent_id' => $p1->id]);

        // Purchase Invoice row for ME-190
        $pi1 = Inquiry::create([
            'parent_id'                   => $p1->id,
            'inquiry_code'                => 'ME-190',
            'enquiry_type'                => 'Purchase_Invoice',
            'customer_id'                 => $tataSteel->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $splValve->id,
            'quantity'                    => 2,
            'qty_invoiced'                => 2,
            'unit_purchase_price'         => 482060.00,
            'purchase_invoice_no'         => '2201752297',
            'invoice_date'                => '2026-05-05',
            'payment_due_date'            => '2026-05-06',
            'total_purchase_value'        => 1137661.60,
            'total_amount_purchase_invoice'=> 1137661.60,
            'purchase_payment_status'     => 'Paid',
            'po_principal_no'             => '25191',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        // Sales Invoice row for ME-190
        $si1 = Inquiry::create([
            'parent_id'                   => $p1->id,
            'inquiry_code'                => 'ME-190',
            'enquiry_type'                => 'Sales_Invoice',
            'customer_id'                 => $tataSteel->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $splValve->id,
            'quantity'                    => 2,
            'qty_billed'                  => 2,
            'price'                       => 587300.00,
            'sales_invoice_no'            => '078',
            'sales_invoice_date'          => '2026-05-21',
            'total_selling_value'         => 1386028.00,
            'sale_basic_gst'              => 1386028.00,
            'portal_submission_required'  => 'Yes',
            'portal_submission_method'    => 'Portal',
            'portal_submission_status'    => 'Accepted',
            'portal_submission_date'      => '2026-05-25',
            'portal_ack_ref'              => 'TS-PORTAL-078-OK',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        MaterialShipmentBatch::create([
            'inquiry_item_id'     => $p1->id,
            'po_principal_no'     => '25191',
            'batch_no'            => 'BATCH-190-01',
            'qty'                 => 2,
            'packages_count'      => 2,
            'status'              => 'Delivered',
            'condition'           => 'Accepted',
            'dispatch_date'       => '2026-05-06',
            'expected_delivery'   => '2026-05-12',
            'actual_receipt_date' => '2026-05-15',
            'ref_doc_type'        => 'LR',
            'ref_doc_no'          => 'LR-TATA-190',
            'lr_date'             => '2026-05-06',
            'invoice_no'          => '2201752297',
            'allocated_customer_po'=> '2100968509/A01',
            'allocated_qty'       => 2,
            'transporter'         => 'V-Trans Logistics',
            'remarks'             => '100% sound receipt and delivery to customer',
            'created_by'          => $admin->id,
        ]);

        PaymentTransaction::create([
            'inquiry_id'       => $si1->id,
            'invoice_type'     => 'Customer',
            'invoice_no'       => '078',
            'amount'           => 1386028.00,
            'total_settled'    => 1386028.00,
            'status'           => 'Completed',
            'transaction_date' => '2026-06-10',
            'reference_no'     => 'UTRIB202606101234',
            'payment_mode'     => 'RTGS',
            'notes'            => 'Full customer settlement received',
            'created_by'       => $admin->id,
        ]);

        PaymentTransaction::create([
            'inquiry_id'       => $pi1->id,
            'invoice_type'     => 'Principal',
            'invoice_no'       => '2201752297',
            'amount'           => 1137661.60,
            'total_settled'    => 1137661.60,
            'status'           => 'Completed',
            'transaction_date' => '2026-06-04',
            'reference_no'     => 'UTRSUP202606045678',
            'payment_mode'     => 'RTGS',
            'notes'            => 'Supplier payment cleared',
            'created_by'       => $admin->id,
        ]);

        // =====================================================================
        // SAMPLE 2: ME-209 — Nava Bharat (Multi-line: 1 + 1 item lines)
        // =====================================================================
        echo "Seeding Sample 2: ME-209 (Nava Bharat - 1 + 1 lines)...\n";
        $p2 = Inquiry::create([
            'inquiry_code'                => 'ME-209',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $navaBharat->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfSpares->id,
            'product_details'             => 'Line 1: Butterfly Valve Spares Kit',
            'quantity'                    => 1,
            'price'                       => 26635.16,
            'basic_amount'                => 53270.33,
            'total_selling_value'         => 62859.00,
            'gst_percent'                 => 18,
            'customer_po_no'              => '4300125341',
            'customer_po_date'            => '2025-12-11',
            'customer_due_date'           => '2026-03-31',
            'delivery_location'           => 'Nava Bharat Dhenkanal',
            'me_ref'                      => 'ME-209',
            'po_principal_no'             => '25210',
            'po_date'                     => '2026-02-05',
            'total_qty_ordered_principal' => 1,
            'unit_purchase_price'         => 22194.00,
            'po_amount_principal'         => 44388.00,
            'total_with_gst'              => 52377.84,
            'status'                      => 'Completed',
            'order_closure_status'        => 'Closed',
            'closed_at'                   => '2026-04-15 11:00:00',
            'closed_by'                   => $admin->id,
            'created_by'                  => $admin->id,
        ]);
        $p2->update(['parent_id' => $p2->id]);

        Inquiry::create([
            'parent_id'                   => $p2->id,
            'inquiry_code'                => 'ME-209',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $navaBharat->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $seatRing->id,
            'product_details'             => 'Line 2: Seat Ring Spares',
            'quantity'                    => 1,
            'price'                       => 26635.17,
            'basic_amount'                => 26635.17,
            'total_selling_value'         => 31429.50,
            'customer_po_no'              => '4300125341',
            'po_principal_no'             => '25210',
            'total_qty_ordered_principal' => 1,
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        Inquiry::create([
            'parent_id'                   => $p2->id,
            'inquiry_code'                => 'ME-209',
            'enquiry_type'                => 'Purchase_Invoice',
            'customer_id'                 => $navaBharat->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfSpares->id,
            'quantity'                    => 2,
            'qty_invoiced'                => 2,
            'purchase_invoice_no'         => '2201751803',
            'invoice_date'                => '2026-03-27',
            'payment_due_date'            => '2026-03-28',
            'total_purchase_value'        => 52377.84,
            'total_amount_purchase_invoice'=> 52377.84,
            'purchase_payment_status'     => 'Paid',
            'po_principal_no'             => '25210',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        Inquiry::create([
            'parent_id'                   => $p2->id,
            'inquiry_code'                => 'ME-209',
            'enquiry_type'                => 'Sales_Invoice',
            'customer_id'                 => $navaBharat->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfSpares->id,
            'quantity'                    => 2,
            'qty_billed'                  => 2,
            'sales_invoice_no'            => '13',
            'sales_invoice_date'          => '2026-04-04',
            'total_selling_value'         => 62859.00,
            'sale_basic_gst'              => 62859.00,
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        // =====================================================================
        // SAMPLE 3: ME-241 — UAIL / Aditya Birla (Stem & Disc, 1 + 1)
        // =====================================================================
        echo "Seeding Sample 3: ME-241 (UAIL / Aditya Birla)...\n";
        $p3 = Inquiry::create([
            'inquiry_code'                => 'ME-241',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $birla->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $stem->id,
            'product_details'             => 'Product A: Stainless Steel Stem',
            'quantity'                    => 1,
            'price'                       => 72435.00,
            'basic_amount'                => 144870.00,
            'total_selling_value'         => 170946.60,
            'gst_percent'                 => 18,
            'customer_po_no'              => '13692325153',
            'customer_po_date'            => '2026-01-29',
            'customer_due_date'           => '2026-07-22',
            'delivery_location'           => 'UAIL Rayagada',
            'me_ref'                      => 'ME-241',
            'po_principal_no'             => '25245',
            'po_date'                     => '2026-01-30',
            'total_qty_ordered_principal' => 1,
            'unit_purchase_price'         => 51741.00,
            'po_amount_principal'         => 103482.00,
            'total_with_gst'              => 122109.00,
            'status'                      => 'Completed',
            'order_closure_status'        => 'Closed',
            'created_by'                  => $admin->id,
        ]);
        $p3->update(['parent_id' => $p3->id]);

        Inquiry::create([
            'parent_id'                   => $p3->id,
            'inquiry_code'                => 'ME-241',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $birla->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $disc->id,
            'product_details'             => 'Product B: Stainless Steel Disc',
            'quantity'                    => 1,
            'price'                       => 72435.00,
            'customer_po_no'              => '13692325153',
            'po_principal_no'             => '25245',
            'total_qty_ordered_principal' => 1,
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        Inquiry::create([
            'parent_id'                   => $p3->id,
            'inquiry_code'                => 'ME-241',
            'enquiry_type'                => 'Purchase_Invoice',
            'customer_id'                 => $birla->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $stem->id,
            'quantity'                    => 2,
            'qty_invoiced'                => 2,
            'purchase_invoice_no'         => '2201753318',
            'invoice_date'                => '2026-07-01',
            'payment_due_date'            => '2026-07-02',
            'total_purchase_value'        => 122109.00,
            'total_amount_purchase_invoice'=> 122109.00,
            'purchase_payment_status'     => 'Paid',
            'po_principal_no'             => '25245',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        Inquiry::create([
            'parent_id'                   => $p3->id,
            'inquiry_code'                => 'ME-241',
            'enquiry_type'                => 'Sales_Invoice',
            'customer_id'                 => $birla->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $stem->id,
            'quantity'                    => 2,
            'qty_billed'                  => 2,
            'sales_invoice_no'            => '153',
            'sales_invoice_date'          => '2026-07-18',
            'total_selling_value'         => 170946.60,
            'sale_basic_gst'              => 170946.60,
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        // =====================================================================
        // SAMPLE 4: ME-285 — JSPL (Delayed Delivery, LD Risk, Follow-up Revisions)
        // =====================================================================
        echo "Seeding Sample 4: ME-285 (JSPL - Overdue Delivery & LD Risk)...\n";
        $p4 = Inquiry::create([
            'inquiry_code'                => 'ME-285',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $jspl->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfSpares->id,
            'product_details'             => 'Spares Kit for 200mm Butterfly Valves',
            'quantity'                    => 6,
            'price'                       => 10442.05,
            'basic_amount'                => 62652.30,
            'total_selling_value'         => 73929.72,
            'gst_percent'                 => 18,
            'customer_po_no'              => '4561183941',
            'customer_po_date'            => '2026-03-14',
            'customer_due_date'           => '2026-06-20', // Overdue test!
            'delivery_location'           => 'JSPL Angul Plant',
            'customer_payment_terms'      => '30 days net',
            'ld_terms'                    => '0.5% per week, max 5% LD for delay',
            'me_ref'                      => 'ME-285',
            'po_principal_no'             => '25290',
            'po_date'                     => '2026-03-17',
            'total_qty_ordered_principal' => 6,
            'unit_purchase_price'         => 8280.00,
            'po_amount_principal'         => 49680.00,
            'total_with_gst'              => 58622.00,
            'principal_co_no'             => 'CO-BRAY-25290',
            'principal_co_date'           => '2026-04-02',
            'original_promise_date'       => '2026-06-15',
            'promise_date'                => '2026-07-15', // Delayed by 1 month!
            'status'                      => 'Completed',
            'order_closure_status'        => 'Closed',
            'closed_at'                   => '2026-09-01 10:00:00',
            'closed_by'                   => $admin->id,
            'order_balance_cancelled_reason' => 'Customer imposed 5% LD penalty (₹3,696.48) for 5 weeks delivery delay past 20.06.2026. Balance accepted and closed.',
            'created_by'                  => $admin->id,
        ]);
        $p4->update(['parent_id' => $p4->id]);

        Inquiry::create([
            'parent_id'                   => $p4->id,
            'inquiry_code'                => 'ME-285',
            'enquiry_type'                => 'Purchase_Invoice',
            'customer_id'                 => $jspl->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfSpares->id,
            'quantity'                    => 6,
            'qty_invoiced'                => 6,
            'purchase_invoice_no'         => '2201753576',
            'invoice_date'                => '2026-07-20',
            'payment_due_date'            => '2026-07-21',
            'total_purchase_value'        => 58622.00,
            'total_amount_purchase_invoice'=> 58622.00,
            'purchase_payment_status'     => 'Paid',
            'po_principal_no'             => '25290',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        $si4 = Inquiry::create([
            'parent_id'                   => $p4->id,
            'inquiry_code'                => 'ME-285',
            'enquiry_type'                => 'Sales_Invoice',
            'customer_id'                 => $jspl->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfSpares->id,
            'quantity'                    => 6,
            'qty_billed'                  => 6,
            'sales_invoice_no'            => '175',
            'sales_invoice_date'          => '2026-07-30',
            'total_selling_value'         => 73929.72,
            'sale_basic_gst'              => 73929.72,
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        PrincipalFollowup::create([
            'inquiry_id'            => $p4->id,
            'followup_date'         => '2026-06-10',
            'contact_person'        => 'Mr. Sharma (Bray Factory Planner)',
            'response'              => 'Raw material casting delay at foundry; promised dispatch pushed from 15.06.2026 to 15.07.2026.',
            'production_status'     => 'Under Production',
            'old_promise_date'      => '2026-06-15',
            'revised_promise_date'  => '2026-07-15',
            'is_promise_date_revised'=> true,
            'revision_reason'       => 'Foundry casting defect re-run',
            'next_followup_date'    => '2026-07-05',
            'responsible_person'    => 'Procurement Team',
            'created_by'            => $admin->id,
        ]);

        PaymentTransaction::create([
            'inquiry_id'       => $si4->id,
            'invoice_type'     => 'Customer',
            'invoice_no'       => '175',
            'amount'           => 70233.24,
            'ld_deduction'     => 3696.48,
            'deduction_reason' => '5% LD penalty imposed for 5-week delay past 20.06.2026',
            'total_settled'    => 73929.72,
            'status'           => 'Completed',
            'transaction_date' => '2026-08-25',
            'reference_no'     => 'JSPL-RTGS-AUG-175',
            'payment_mode'     => 'RTGS',
            'notes'            => 'Customer settled net of LD penalty',
            'created_by'       => $admin->id,
        ]);

        // =====================================================================
        // REGISTER SAMPLE: ME-267 — JSPL (Partial / Split Delivery & Invoicing: 1 + 8 units)
        // =====================================================================
        echo "Seeding Register Sample: ME-267 (JSPL - Split Deliveries 1 + 8 units)...\n";
        $kgvValve = Item::firstOrCreate(['name' => 'Knife Gate Valve KGV'], ['mat_code' => 'KGV-001', 'description' => 'Knife Gate Valve 300NB / 400NB']);
        $p267 = Inquiry::create([
            'inquiry_code'                => 'ME-267',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $jspl->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $kgvValve->id,
            'product_details'             => 'Line 1: 300 NB KGV (Qty 5) - JSPL 2x525 MW Monnet',
            'quantity'                    => 5,
            'price'                       => 158666.67,
            'basic_amount'                => 1428000.00,
            'total_selling_value'         => 1685040.00,
            'gst_percent'                 => 18,
            'customer_po_no'              => '4561182624',
            'customer_po_date'            => '2026-03-02',
            'customer_due_date'           => '2026-08-30',
            'delivery_location'           => 'JSPL 2x525 MW Plant Monnet',
            'me_ref'                      => 'ME-267',
            'po_principal_no'             => '25280',
            'po_date'                     => '2026-03-03',
            'total_qty_ordered_principal' => 5,
            'unit_purchase_price'         => 118885.11,
            'po_amount_principal'         => 1069966.00,
            'total_with_gst'              => 1262560.00,
            'principal_co_no'             => 'CO-BRAY-25280',
            'principal_co_date'           => '2026-03-15',
            'promise_date'                => '2026-07-25',
            'original_promise_date'       => '2026-07-25',
            'status'                      => 'Completed',
            'order_closure_status'        => 'Closed',
            'created_by'                  => $admin->id,
        ]);
        $p267->update(['parent_id' => $p267->id]);

        Inquiry::create([
            'parent_id'                   => $p267->id,
            'inquiry_code'                => 'ME-267',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $jspl->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $kgvValve->id,
            'product_details'             => 'Line 2: 400 NB KGV (Qty 4) - JSPL 2x525 MW Monnet',
            'quantity'                    => 4,
            'price'                       => 158666.67,
            'customer_po_no'              => '4561182624',
            'po_principal_no'             => '25280',
            'total_qty_ordered_principal' => 4,
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        // Split Purchase Invoice 1: 1 unit on 18.07.26
        $pi267_1 = Inquiry::create([
            'parent_id'                   => $p267->id,
            'inquiry_code'                => 'ME-267',
            'enquiry_type'                => 'Purchase_Invoice',
            'customer_id'                 => $jspl->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $kgvValve->id,
            'quantity'                    => 1,
            'qty_invoiced'                => 1,
            'purchase_invoice_no'         => '2201753304',
            'invoice_date'                => '2026-07-18',
            'payment_due_date'            => '2026-07-19',
            'total_purchase_value'        => 103550.00,
            'total_amount_purchase_invoice'=> 103550.00,
            'purchase_payment_status'     => 'Paid',
            'po_principal_no'             => '25280',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        // Split Purchase Invoice 2: 8 units (4+4) on 30.07.26
        $pi267_2 = Inquiry::create([
            'parent_id'                   => $p267->id,
            'inquiry_code'                => 'ME-267',
            'enquiry_type'                => 'Purchase_Invoice',
            'customer_id'                 => $jspl->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $kgvValve->id,
            'quantity'                    => 8,
            'qty_invoiced'                => 8,
            'purchase_invoice_no'         => '2201753824',
            'invoice_date'                => '2026-07-30',
            'payment_due_date'            => '2026-07-31',
            'total_purchase_value'        => 1159010.00,
            'total_amount_purchase_invoice'=> 1159010.00,
            'purchase_payment_status'     => 'Paid',
            'po_principal_no'             => '25280',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        // Split Sales Invoice 1: 1 unit on 18.07.26 (SI 155)
        $si267_1 = Inquiry::create([
            'parent_id'                   => $p267->id,
            'inquiry_code'                => 'ME-267',
            'enquiry_type'                => 'Sales_Invoice',
            'customer_id'                 => $jspl->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $kgvValve->id,
            'quantity'                    => 1,
            'qty_billed'                  => 1,
            'sales_invoice_no'            => '155',
            'sales_invoice_date'          => '2026-07-18',
            'total_selling_value'         => 136880.00,
            'sale_basic_gst'              => 136880.00,
            'portal_submission_required'  => 'Yes',
            'portal_submission_method'    => 'Vendor Portal',
            'portal_submission_status'    => 'Accepted',
            'portal_ack_ref'              => 'JSPL-PORTAL-ACK-155',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        // Split Sales Invoice 2: 8 units on 05.08.26 (SI 189)
        $si267_2 = Inquiry::create([
            'parent_id'                   => $p267->id,
            'inquiry_code'                => 'ME-267',
            'enquiry_type'                => 'Sales_Invoice',
            'customer_id'                 => $jspl->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $kgvValve->id,
            'quantity'                    => 8,
            'qty_billed'                  => 8,
            'sales_invoice_no'            => '189',
            'sales_invoice_date'          => '2026-08-05',
            'total_selling_value'         => 1548160.00,
            'sale_basic_gst'              => 1548160.00,
            'portal_submission_required'  => 'Yes',
            'portal_submission_method'    => 'Vendor Portal',
            'portal_submission_status'    => 'Accepted',
            'portal_ack_ref'              => 'JSPL-PORTAL-ACK-189',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        PaymentTransaction::create([
            'inquiry_id'       => $si267_1->id,
            'invoice_type'     => 'Customer',
            'invoice_no'       => '155',
            'amount'           => 136880.00,
            'total_settled'    => 136880.00,
            'status'           => 'Completed',
            'transaction_date' => '2026-08-15',
            'payment_mode'     => 'RTGS',
            'reference_no'     => 'JSPL-RTGS-SI155',
            'created_by'       => $admin->id,
        ]);

        PaymentTransaction::create([
            'inquiry_id'       => $si267_2->id,
            'invoice_type'     => 'Customer',
            'invoice_no'       => '189',
            'amount'           => 1548160.00,
            'total_settled'    => 1548160.00,
            'status'           => 'Completed',
            'transaction_date' => '2026-09-02',
            'payment_mode'     => 'RTGS',
            'reference_no'     => 'JSPL-RTGS-SI189',
            'created_by'       => $admin->id,
        ]);

        // =====================================================================
        // SAMPLE 5: ME-289 — Vedanta Lanjigarh (4 + 2 + 4 high volume)
        // =====================================================================
        echo "Seeding Sample 5: ME-289 (Vedanta Lanjigarh - 4 + 2 + 4 units)...\n";
        $p5 = Inquiry::create([
            'inquiry_code'                => 'ME-289',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $vedanta->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfValve->id,
            'product_details'             => 'Line 1: Butterfly Valve 100mm (Qty 4)',
            'quantity'                    => 4,
            'price'                       => 17507.00,
            'basic_amount'                => 175070.00,
            'total_selling_value'         => 206582.60,
            'gst_percent'                 => 18,
            'customer_po_no'              => 'VED-PO-2026-882',
            'customer_po_date'            => '2026-03-18',
            'customer_due_date'           => '2026-07-01',
            'delivery_location'           => 'Vedanta Lanjigarh Alumina Refinery',
            'me_ref'                      => 'ME-289',
            'po_principal_no'             => '25294',
            'po_date'                     => '2026-03-21',
            'total_qty_ordered_principal' => 4,
            'unit_purchase_price'         => 13782.60,
            'po_amount_principal'         => 137826.00,
            'total_with_gst'              => 162635.00,
            'status'                      => 'Completed',
            'order_closure_status'        => 'Closed',
            'created_by'                  => $admin->id,
        ]);
        $p5->update(['parent_id' => $p5->id]);

        Inquiry::create([
            'parent_id'                   => $p5->id,
            'inquiry_code'                => 'ME-289',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $vedanta->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfValve->id,
            'product_details'             => 'Line 2: Butterfly Valve 150mm (Qty 2)',
            'quantity'                    => 2,
            'price'                       => 26260.50,
            'customer_po_no'              => 'VED-PO-2026-882',
            'po_principal_no'             => '25294',
            'total_qty_ordered_principal' => 2,
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        Inquiry::create([
            'parent_id'                   => $p5->id,
            'inquiry_code'                => 'ME-289',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $vedanta->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfValve->id,
            'product_details'             => 'Line 3: Butterfly Valve 200mm (Qty 4)',
            'quantity'                    => 4,
            'price'                       => 13130.25,
            'customer_po_no'              => 'VED-PO-2026-882',
            'po_principal_no'             => '25294',
            'total_qty_ordered_principal' => 4,
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        Inquiry::create([
            'parent_id'                   => $p5->id,
            'inquiry_code'                => 'ME-289',
            'enquiry_type'                => 'Purchase_Invoice',
            'customer_id'                 => $vedanta->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfValve->id,
            'quantity'                    => 10,
            'qty_invoiced'                => 10,
            'purchase_invoice_no'         => '2201754659',
            'invoice_date'                => '2026-09-11',
            'payment_due_date'            => '2026-09-12',
            'total_purchase_value'        => 162635.00,
            'total_amount_purchase_invoice'=> 162635.00,
            'purchase_payment_status'     => 'Paid',
            'po_principal_no'             => '25294',
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        Inquiry::create([
            'parent_id'                   => $p5->id,
            'inquiry_code'                => 'ME-289',
            'enquiry_type'                => 'Sales_Invoice',
            'customer_id'                 => $vedanta->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $bfValve->id,
            'quantity'                    => 10,
            'qty_billed'                  => 10,
            'sales_invoice_no'            => '243',
            'sales_invoice_date'          => '2026-09-17',
            'total_selling_value'         => 206582.60,
            'sale_basic_gst'              => 206582.60,
            'status'                      => 'Completed',
            'created_by'                  => $admin->id,
        ]);

        // =====================================================================
        // SAMPLE 6: ME-301 — Section 16 Combined Order Scenario
        // =====================================================================
        echo "Seeding Sample 6: ME-301 (Section 16 Combined Order Scenario)...\n";
        $p6 = Inquiry::create([
            'inquiry_code'                => 'ME-301',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $tataSteel->id,
            'principal_id'                => $bray->id,
            'item_id'                     => $splValve->id,
            'product_details'             => 'Combined Valve Requirement (Tata Steel + JSPL + 2 Buffer Stock)',
            'quantity'                    => 15,
            'total_qty_ordered_principal' => 15,
            'unit_purchase_price'         => 450000.00,
            'po_amount_principal'         => 6750000.00,
            'total_with_gst'              => 7965000.00,
            'po_principal_no'             => 'PO-COMB-2026-01',
            'po_date'                     => '2026-10-15',
            'promise_date'                => '2026-12-10',
            'is_combined_principal_order' => true,
            'unallocated_qty'             => 2, // 2 units buffer safety stock!
            'combined_source_item_ids'    => [
                [
                    'customer_name'  => 'Tata Steel',
                    'customer_po_no' => 'TS-COMB-PO-901',
                    'customer_due'   => '2026-12-20',
                    'allocated_qty'  => 5,
                ],
                [
                    'customer_name'  => 'JSPL',
                    'customer_po_no' => 'JSPL-COMB-PO-402',
                    'customer_due'   => '2026-12-25',
                    'allocated_qty'  => 8,
                ]
            ],
            'status'                      => 'Ordered Placed',
            'order_closure_status'        => 'In Progress',
            'created_by'                  => $admin->id,
        ]);
        $p6->update(['parent_id' => $p6->id]);

        // =====================================================================
        // SAMPLE 7: ME-302 — Section 17 Advance Order (Customer PO Awaited)
        // =====================================================================
        echo "Seeding Sample 7: ME-302 (Section 17 Advance Order - Customer PO Awaited)...\n";
        $p7 = Inquiry::create([
            'inquiry_code'                  => 'ME-302',
            'enquiry_type'                  => 'PO_Principal',
            'customer_id'                   => null,
            'principal_id'                  => $nord->id,
            'item_id'                       => $gearbox->id,
            'product_details'               => 'Heavy Duty Helical Bevel Gearbox (5 Months Lead Time)',
            'quantity'                      => 10,
            'total_qty_ordered_principal'   => 10,
            'unit_purchase_price'           => 120000.00,
            'po_amount_principal'           => 1200000.00,
            'total_with_gst'                => 1416000.00,
            'po_principal_no'               => 'ADV-NORD-2026-088',
            'po_date'                       => '2026-10-01',
            'promise_date'                  => '2027-03-01',
            'is_advance_principal_order'    => true,
            'advance_order_reason'          => '5-month factory import lead time; early order placed to protect critical plant overhaul shutdown.',
            'expected_customer_name'        => 'NTPC',
            'expected_customer_location'    => 'NTPC Talcher Thermal Power Station',
            'expected_delivery_requirement' => 'March 2027 Overhaul',
            'unallocated_qty'               => 10,
            'status'                        => 'Ordered Placed',
            'order_closure_status'          => 'In Progress',
            'created_by'                    => $admin->id,
        ]);
        $p7->update(['parent_id' => $p7->id]);

        // =====================================================================
        // SAMPLE 8: ME-303 — Active In-Transit Shipment & Follow-up Due Today
        // =====================================================================
        echo "Seeding Sample 8: ME-303 (In-Transit Shipment & Due Follow-up)...\n";
        $p8 = Inquiry::create([
            'inquiry_code'                => 'ME-303',
            'enquiry_type'                => 'PO_Principal',
            'customer_id'                 => $iffco->id,
            'principal_id'                => $asco->id,
            'item_id'                     => $solenoid->id,
            'product_details'             => 'Pneumatic 3/2 Solenoid Valve ASCO RedHat',
            'quantity'                    => 4,
            'price'                       => 15000.00,
            'basic_amount'                => 60000.00,
            'total_selling_value'         => 70800.00,
            'customer_po_no'              => 'IFFCO-PO-771',
            'customer_po_date'            => '2026-10-10',
            'customer_due_date'           => '2026-11-15',
            'delivery_location'           => 'IFFCO Paradeep Fertilizer Plant',
            'customer_payment_terms'      => '30 days net',
            'me_ref'                      => 'ME-303',
            'po_principal_no'             => 'PO-ASCO-2026-44',
            'po_date'                     => '2026-10-12',
            'total_qty_ordered_principal' => 4,
            'unit_purchase_price'         => 10500.00,
            'po_amount_principal'         => 42000.00,
            'total_with_gst'              => 49560.00,
            'principal_co_no'             => 'CO-ASCO-9988',
            'principal_co_date'           => '2026-10-16',
            'promise_date'                => '2026-10-24',
            'purchase_invoice_no'         => 'PI-ASCO-1109',
            'invoice_date'                => '2026-10-25',
            'status'                      => 'Ordered Placed',
            'order_closure_status'        => 'In Progress',
            'created_by'                  => $admin->id,
        ]);
        $p8->update(['parent_id' => $p8->id]);

        MaterialShipmentBatch::create([
            'inquiry_item_id'     => $p8->id,
            'po_principal_no'     => 'PO-ASCO-2026-44',
            'batch_no'            => 'BATCH-ASCO-01',
            'qty'                 => 4,
            'packages_count'      => 1,
            'status'              => 'In Transit',
            'condition'           => 'Under Inspection',
            'dispatch_date'       => '2026-10-26',
            'expected_delivery'   => '2026-10-31',
            'actual_receipt_date' => null, // in-transit on highway
            'ref_doc_type'        => 'LR',
            'ref_doc_no'          => 'LR-GATI-9981',
            'lr_date'             => '2026-10-26',
            'invoice_no'          => 'PI-ASCO-1109',
            'allocated_customer_po'=> 'IFFCO-PO-771',
            'allocated_qty'       => 4,
            'transporter'         => 'GATI KWE Express',
            'remarks'             => 'Consignment dispatched from Pune factory, in transit to Paradeep',
            'created_by'          => $admin->id,
        ]);

        PrincipalFollowup::create([
            'inquiry_id'            => $p8->id,
            'followup_date'         => Carbon::today()->toDateString(),
            'contact_person'        => 'Logistics Desk GATI',
            'response'              => 'Vehicle reached Bhubaneswar transit hub, final delivery scheduled for tomorrow.',
            'production_status'     => 'In Transit',
            'next_followup_date'    => Carbon::today()->addDays(1)->toDateString(),
            'responsible_person'    => 'Logistics Coordinator',
            'created_by'            => $admin->id,
        ]);

        echo "=== ALL SAMPLE TRANSACTIONS INSERTED SUCCESSFULLY! ===\n";
    }
}
