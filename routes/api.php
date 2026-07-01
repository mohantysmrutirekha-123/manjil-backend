<?php
// use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\API\AuthController;
// use App\Http\Controllers\API\InquiryController;
// use App\Http\Controllers\API\QuotationController;
// use App\Http\Controllers\API\AuditLogController;
// use App\Http\Controllers\API\DashboardController;
// use App\Http\Controllers\API\ItemController;

// Route::get('/test', function () {
//     return response()->json(['message' => 'API working','pass'=>bcrypt('admin')]);
// });

// Route::post('/login', [AuthController::class, 'login']);
// Route::post('/register', [AuthController::class, 'register']);

// // Protected routes
// Route::middleware('auth:sanctum')->group(function () {

//     // Inquiry Routes
//     Route::post('/inquiry', [InquiryController::class, 'store']);

//     Route::get('/my-inquiry', [InquiryController::class, 'myInquiry']);
    
//     Route::get('/all-inquiry', [InquiryController::class, 'allInquiry']);
    
//     // Get all items under one inquiry
//     Route::get('/inquiry/{parentId}', [InquiryController::class, 'inquiryDetails']);
    
//     Route::put('/inquiry/{id}', [InquiryController::class, 'update']);
    
//     Route::delete('/inquiry/{id}', [InquiryController::class, 'destroy']);


//     // Quotation Routes
//     Route::post('/quotation', [QuotationController::class, 'store']);

//     Route::get('/my-quotation', [QuotationController::class, 'myQuotation']);
//     Route::get('/all-quotation', [QuotationController::class, 'allQuotation']);

//     Route::put('/quotation/{id}', [QuotationController::class, 'update']);
//     Route::delete('/quotation/{id}', [QuotationController::class, 'destroy']);

//      // Audio Report Routes
//     Route::get('/audit-logs', [AuditLogController::class, 'index']);
//     Route::get('/audit-report', [AuditLogController::class, 'report']);

//     // Dashboard Report API
//     Route::get('/dashboard', [DashboardController::class, 'dashboard']);

//     // User List
//     Route::get('/users', [AuthController::class, 'userList']);

//     // Update User
//     Route::put('/user/{id}', [AuthController::class, 'updateUser']);

//     // Delete User
//     Route::delete('/user/{id}', [AuthController::class, 'deleteUser']);

//      //Item Routes
//     Route::post('/item', [ItemController::class, 'store']);
//     Route::get('/all-item', [ItemController::class, 'index']);
//     Route::get('/item/{id}', [ItemController::class, 'show']);
//     Route::put('/item/{id}', [ItemController::class, 'update']);
//     Route::delete('/item/{id}', [ItemController::class, 'destroy']);

// });


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\InquiryController;
use App\Http\Controllers\API\QuotationController;
use App\Http\Controllers\API\AuditLogController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\ItemController;
use App\Http\Controllers\API\OpportunityController;

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

});