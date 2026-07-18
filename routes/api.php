<?php

use App\Http\Controllers\API\AuditLogController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\Api\CustomerPoController;
use App\Http\Controllers\Api\CustomerQuoteController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\InquiryController;
use App\Http\Controllers\API\ItemController;
use App\Http\Controllers\Api\NegotiationController;
use App\Http\Controllers\API\OpportunityController;
use App\Http\Controllers\Api\PrincipalQuoteController;
use App\Http\Controllers\API\QuotationController;
use App\Http\Controllers\Api\PoToPrincipalController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Controllers\Api\SalesInvoiceController;

Route::get('/test', function () {
    return response()->json(['message' => 'API working','pass'=>bcrypt('admin')]);
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

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


    Route::put('/convertToprincipalqutation/{id}', [InquiryController::class, 'convertToprincipalqutation']);

    Route::get('/principalquotationList', [PrincipalQuoteController::class, 'principalquotationList']);


  Route::post('/principal-quotation/{id}', [PrincipalQuoteController::class, 'update']);



  Route::post('/convertTocustomerQuotation/{id}',[CustomerQuoteController::class, 'convertTocustomerQuotation']);

  Route::get('/customerquotationList', [CustomerQuoteController::class, 'customerquotationList']);

   Route::post('/customer-quotation/{id}', [CustomerQuoteController::class, 'update']);

   Route::post('/convertTonegotiation/{id}',[NegotiationController::class, 'convertTonegotiation']);
   Route::get('/negotiationList', [NegotiationController::class, 'negotiationList']);
   Route::post('/negotiation/{id}', [NegotiationController::class, 'update']);



    Route::post('/convertTocustomerpo/{id}',[CustomerPoController::class, 'convertTocustomerpo']);

    Route::get('/customerpoList', [CustomerPoController::class, 'customerpoList']);

    Route::post('/customer-po/{id}', [CustomerPoController::class, 'update']);


    Route::post('/convertTopoprincipal/{id}', [PoToPrincipalController::class, 'convertTopoprincipal']);
    Route::get('/poprincipalList', [PoToPrincipalController::class, 'poprincipalList']);
    Route::post('/po-to-principal/{id}', [PoToPrincipalController::class, 'update']);

    Route::post('/convertTopurchaseinvoice/{id}', [PurchaseInvoiceController::class, 'convertTopurchaseinvoice']);
    Route::get('/purchaseinvoiceList', [PurchaseInvoiceController::class, 'purchaseinvoiceList']);
    Route::post('/purchase-invoice/{id}', [PurchaseInvoiceController::class, 'update']);

    Route::post('/convertTosalesinvoice/{id}', [SalesInvoiceController::class, 'convertTosalesinvoice']);
    Route::get('/sellinvoiceList', [SalesInvoiceController::class, 'sellinvoiceList']);
    Route::post('/sales-invoice/{id}', [SalesInvoiceController::class, 'update']);



    // Route::post('/opportunity/{id}/convert-principal-quotation',[OpportunityController::class, 'convertOpportunityToPrincipalQuotation']);
    Route::post('/opportunity/convert-to-principal-quotation', [OpportunityController::class, 'convertOpportunityToPrincipalQuotation']);



    Route::post('/principal-quotation', [PrincipalQuoteController::class, 'store']);
    Route::post('/customer-quotation', [CustomerQuoteController::class, 'store']);
    Route::post('/negotiation-quotation', [NegotiationController::class, 'store']);
    Route::post('/customer-po', [CustomerPoController::class, 'store']);
    Route::post('/po-to-principal', [PoToPrincipalController::class, 'store']);


});