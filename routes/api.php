<?php

use App\Http\Controllers\API\AuditLogController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\Api\CustomerPoController;
use App\Http\Controllers\Api\CustomerQuoteController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\InquiryController;
use App\Http\Controllers\API\ItemController;
use App\Http\Controllers\Api\MaterialTrackingController;
use App\Http\Controllers\Api\NegotiationController;
use App\Http\Controllers\API\OpportunityController;
use App\Http\Controllers\API\AdvancePrincipalOrderController;
use App\Http\Controllers\API\OrderClosureController;
use App\Http\Controllers\API\OperationalDashboardController;
use App\Http\Controllers\Api\PaymentTrackingController;
use App\Http\Controllers\Api\PoToPrincipalController;
use App\Http\Controllers\API\PrincipalFollowupController;
use App\Http\Controllers\Api\PrincipalQuoteController;
use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Controllers\API\QuotationController;
use App\Http\Controllers\Api\SalesInvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/test', function () {
  return response()->json(['message' => 'API working', 'pass' => bcrypt('admin')]);
});

Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
  Route::post('/register', [AuthController::class, 'register']);
  Route::get('/modules', [AuthController::class, 'modules']);
  Route::get('/users', [AuthController::class, 'userList']);
  Route::put('/users/{id}', [AuthController::class, 'updateUser']);
  Route::delete('/users/{id}', [AuthController::class, 'deleteUser']);
  Route::get('/users/{id}/permissions', [AuthController::class, 'getUserPermissions']);
  Route::put('/users/{id}/permissions', [AuthController::class, 'updatePermissions']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

  // Inquiry Routes
  Route::post('/inquiry', [InquiryController::class, 'store']);

  Route::get('/my-inquiry', [InquiryController::class, 'myInquiry']);

  Route::get('/all-inquiry', [InquiryController::class, 'allInquiry']);

  // Get all items under one inquiry
  Route::get('/inquiry/{parentId}', [InquiryController::class, 'inquiryDetails']);

  Route::put('/inquiry/{id}', [InquiryController::class, 'update']);

  Route::delete('/inquiry/{id}', [InquiryController::class, 'destroy']);

  Route::get('/audit-logs/enquiry/{code}', [AuditLogController::class, 'enquiryLogs']);


  // Quotation Routes
  Route::post('/quotation', [QuotationController::class, 'store']);

  Route::get('/my-quotation', [QuotationController::class, 'myQuotation']);
  Route::get('/all-quotation', [QuotationController::class, 'allQuotation']);

  Route::put('/quotation/{id}', [QuotationController::class, 'update']);
  Route::delete('/quotation/{id}', [QuotationController::class, 'destroy']);

  // Audio Report Routes
  Route::get('/audit-logs', [AuditLogController::class, 'index']);
  Route::get('/audit-report', [AuditLogController::class, 'report']);

  // Dashboard Report API
  Route::get('/dashboard', [DashboardController::class, 'dashboard']);

  // User List
  Route::get('/users', [AuthController::class, 'userList']);

  // Update User
  Route::put('/user/{id}', [AuthController::class, 'updateUser']);

  // Delete User
  Route::delete('/user/{id}', [AuthController::class, 'deleteUser']);

  //Item Routes
  Route::post('/item', [ItemController::class, 'store']);
  Route::get('/all-item', [ItemController::class, 'index']);
  Route::get('/item/{id}', [ItemController::class, 'show']);
  Route::put('/item/{id}', [ItemController::class, 'update']);
  Route::delete('/item/{id}', [ItemController::class, 'destroy']);

  // ── Opportunity Routes ──────────────────────────────────────────────
  Route::post('/opportunity', [OpportunityController::class, 'store']);

  Route::get('/my-opportunity', [OpportunityController::class, 'myOpportunity']);
  Route::get('/all-opportunity', [OpportunityController::class, 'allOpportunity']);

  // Get all item rows under one opportunity (parentId)
  Route::get('/opportunity/{parentId}', [OpportunityController::class, 'show']);

  // Update head fields of an opportunity
  Route::put('/opportunity/{id}', [OpportunityController::class, 'update']);

  // Delete an entire opportunity (head + items)
  Route::delete('/opportunity/{id}', [OpportunityController::class, 'destroy']);

  // Item-row level actions
  Route::post('/opportunity/{parentId}/item', [OpportunityController::class, 'addItem']);
  Route::put('/opportunity-item/{itemRowId}', [OpportunityController::class, 'updateItem']);
  Route::delete('/opportunity-item/{itemRowId}', [OpportunityController::class, 'deleteItem']);

  // ── Opportunity → Enquiry conversion ────────────────────────────────
  Route::post('/opportunity/convert-to-enquiry', [OpportunityController::class, 'convertToEnquiry']);



  Route::post('/merge-to-enquiry', [InquiryController::class, 'convertBoToEnquiry']);
  Route::put('/convertToprincipalqutation/{id}', [InquiryController::class, 'convertToprincipalqutation']);

  Route::get('/principalquotationList', [PrincipalQuoteController::class, 'principalquotationList']);


  Route::post('/principal-quotation/{id}', [PrincipalQuoteController::class, 'update']);



  Route::post('/convertTocustomerQuotation/{id}', [CustomerQuoteController::class, 'convertTocustomerQuotation']);

  Route::get('/customerquotationList', [CustomerQuoteController::class, 'customerquotationList']);

  Route::post('/customer-quotation/{id}', [CustomerQuoteController::class, 'update']);

  Route::post('/convertTonegotiation/{id}', [NegotiationController::class, 'convertTonegotiation']);
  Route::get('/negotiationList', [NegotiationController::class, 'negotiationList']);
  Route::post('/negotiation/{id}', [NegotiationController::class, 'update']);



  Route::post('/convertTocustomerpo/{id}', [CustomerPoController::class, 'convertTocustomerpo']);

  Route::get('/customerpoList', [CustomerPoController::class, 'customerpoList']);

  Route::post('/customer-po/{id}', [CustomerPoController::class, 'update']);
  Route::delete('/customer-po/{id}', [CustomerPoController::class, 'destroy']);


  // Route::put('/convert-bo-to-enquiry/{boId}', [InquiryController::class, 'convertBoToEnquiry']);


  Route::post('/convertTopoprincipal/{id}', [PoToPrincipalController::class, 'convertTopoprincipal']);
  Route::get('/poprincipalList', [PoToPrincipalController::class, 'poprincipalList']);
  Route::post('/po-to-principal/{id}', [PoToPrincipalController::class, 'update']);

  Route::post('/convertTopurchaseinvoice/{id}', [PurchaseInvoiceController::class, 'convertTopurchaseinvoice']);
  Route::get('/purchaseinvoiceList', [PurchaseInvoiceController::class, 'purchaseinvoiceList']);
  Route::post('/purchase-invoice/{id}', [PurchaseInvoiceController::class, 'update']);
  Route::post('/purchase-invoice/{id}/pay-later', [PurchaseInvoiceController::class, 'savePayLater']);
  Route::post('/purchase-invoice', [PurchaseInvoiceController::class, 'store']);

  Route::post('/convertTosalesinvoice/{id}', [SalesInvoiceController::class, 'convertTosalesinvoice']);
  Route::get('/sellinvoiceList', [SalesInvoiceController::class, 'sellinvoiceList']);
  Route::post('/sales-invoice/{id}', [SalesInvoiceController::class, 'update']);
  Route::put('/sales-invoice/{id}/portal-submission', [SalesInvoiceController::class, 'updatePortalSubmission']);



  // Route::post('/opportunity/{id}/convert-principal-quotation',[OpportunityController::class, 'convertOpportunityToPrincipalQuotation']);
  Route::post('/opportunity/convert-to-principal-quotation', [OpportunityController::class, 'convertOpportunityToPrincipalQuotation']);



  Route::post('/principal-quotation', [PrincipalQuoteController::class, 'store']);
  Route::post('/customer-quotation', [CustomerQuoteController::class, 'store']);
  Route::post('/negotiation-quotation', [NegotiationController::class, 'store']);
  Route::post('/customer-po', [CustomerPoController::class, 'store']);
  Route::post('/po-to-principal', [PoToPrincipalController::class, 'store']);















  // ── Material Tracking ───────────────────────────────────────────────
  Route::get('/material-tracking', [MaterialTrackingController::class, 'index']);
  Route::post('/material-tracking/{inquiryItemId}/batch', [MaterialTrackingController::class, 'storeBatch']);
  Route::put('/material-tracking/batch/{batchId}', [MaterialTrackingController::class, 'updateBatch']);
  Route::delete('/material-tracking/batch/{batchId}', [MaterialTrackingController::class, 'destroyBatch']);
  Route::post('/material-tracking/bulk-batch', [MaterialTrackingController::class, 'bulkStoreBatch']);

  // ── Payment Tracking ─────────────────────────────────────────────────
  Route::get('/payment-tracking', [PaymentTrackingController::class, 'index']);
  Route::post('/payment-tracking/{inquiryId}/transaction', [PaymentTrackingController::class, 'storeTransaction']);
  Route::put('/payment-tracking/transaction/{transactionId}', [PaymentTrackingController::class, 'updateTransaction']);
  Route::delete('/payment-tracking/transaction/{transactionId}', [PaymentTrackingController::class, 'destroyTransaction']);
  Route::post('/payment-tracking/bulk-transaction', [PaymentTrackingController::class, 'bulkStoreTransaction']);
  Route::post('/payment-tracking/{id}/pay-later-paid', [PaymentTrackingController::class, 'markPayLaterPaid']);
  Route::put('/payment-tracking/{id}/due-date-basis', [PaymentTrackingController::class, 'updateDueDateBasis']);

  // ── Stage 5: Principal Follow-up & Promise Date Revision History ─────
  Route::get('/principal-followups/due', [PrincipalFollowupController::class, 'dueFollowups']);
  Route::get('/principal-followups/{inquiryId}', [PrincipalFollowupController::class, 'index']);
  Route::post('/principal-followups/{inquiryId}', [PrincipalFollowupController::class, 'store']);
  Route::get('/principal-followups/{inquiryId}/promise-history', [PrincipalFollowupController::class, 'promiseDateHistory']);

  // ── Section 16 & 17: Advance Principal Orders & Combined Orders ──────
  Route::post('/advance-principal-orders', [AdvancePrincipalOrderController::class, 'store']);
  Route::post('/advance-principal-orders/{id}/link-customer-po', [AdvancePrincipalOrderController::class, 'linkCustomerPo']);
  Route::post('/combine-customer-orders-to-principal', [AdvancePrincipalOrderController::class, 'combineCustomerOrders']);
  Route::post('/principal-orders/{id}/reallocate', [AdvancePrincipalOrderController::class, 'reallocate']);

  // ── Section 20: Order Status and Formal Order Closure ─────────────────
  Route::get('/orders/{id}/closure-check', [OrderClosureController::class, 'checkClosure']);
  Route::post('/orders/{id}/close-order', [OrderClosureController::class, 'closeOrder']);
  Route::post('/orders/{id}/reopen-order', [OrderClosureController::class, 'reopenOrder']);

  // ── Section 19: Operational Command Center Dashboard ──────────────────
  Route::get('/operational-dashboard/metrics', [OperationalDashboardController::class, 'summary']);
  Route::get('/operational-dashboard/action-list/{type}', [OperationalDashboardController::class, 'actionList']);

});