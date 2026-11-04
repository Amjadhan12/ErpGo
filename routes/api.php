<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OpeningBalanceController;
use App\Http\Controllers\Api\BusinessPartnerController;
use App\Http\Controllers\Api\PartnerOpeningBalanceController;
use App\Http\Controllers\Api\PartnerBalanceReportController;
use App\Http\Controllers\Api\SupplierPaymentController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SalePaymentController;
use App\Http\Controllers\Api\FinancialAccountController;
use App\Http\Controllers\Api\InventoryReportController;
use App\Http\Controllers\Api\InventoryMovementReportController;
use App\Http\Controllers\Api\PurchaseReturnController;
use App\Http\Controllers\Api\SaleReturnController;
use App\Http\Controllers\Api\PurchaseReturnSettlementController;
use App\Http\Controllers\Api\SaleReturnSettlementController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\CashTransferController;
use App\Http\Controllers\Api\DailyBookNoteController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\CustomerPortalController;
// Public authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Routes requiring authentication
Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

// Secretary-only test route
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->get('/secretary-check', function (Request $request) {
        return response()->json([
            'message' => 'Secretary access granted.',
            'user' => $request->user(),
        ]);
    });

// Customer-only test route
Route::middleware(['auth:sanctum', 'role:Customer'])
    ->get('/customer-check', function (Request $request) {
        return response()->json([
            'message' => 'Customer access granted.',
            'user' => $request->user(),
        ]);
    }); 
// Secretary-only opening balance management
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('opening-balances')
    ->group(function () {
        Route::get('/', [OpeningBalanceController::class, 'index']);
        Route::post('/', [OpeningBalanceController::class, 'store']);
    });

    Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('business-partners')
    ->group(function () {
        Route::get('/', [BusinessPartnerController::class, 'index']);
        Route::post('/', [BusinessPartnerController::class, 'store']);
        Route::get('/{businessPartner}', [BusinessPartnerController::class, 'show']);
        Route::put('/{businessPartner}', [BusinessPartnerController::class, 'update']);
        Route::patch('/{businessPartner}', [BusinessPartnerController::class, 'update']);
        Route::delete('/{businessPartner}', [BusinessPartnerController::class, 'destroy']);
    });
    Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('partner-opening-balances')
    ->group(function () {
        Route::get('/', [PartnerOpeningBalanceController::class, 'index']);
        Route::post('/', [PartnerOpeningBalanceController::class, 'store']);
    });
    Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->get(
        '/reports/partner-balances',
        [PartnerBalanceReportController::class, 'index']
    );

    Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('purchases')
    ->group(function () {
        Route::get('/', [PurchaseController::class, 'index']);
        Route::post('/', [PurchaseController::class, 'store']);
    });
    Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('supplier-payments')
    ->group(function () {
        Route::get('/', [SupplierPaymentController::class, 'index']);
        Route::post('/', [SupplierPaymentController::class, 'store']);
    });
    // Secretary-only sales management
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('sales')
    ->group(function () {
        Route::get('/', [SaleController::class, 'index']);
        Route::post('/', [SaleController::class, 'store']);
        Route::get('/{id}', [SaleController::class, 'show']);
    });

// Secretary-only sale payments
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('sale-payments')
    ->group(function () {
        Route::get('/', [SalePaymentController::class, 'index']);
        Route::post('/', [SalePaymentController::class, 'store']);
    });
    // Secretary-only financial account balances
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->get(
        '/financial-accounts/balances',
        [FinancialAccountController::class, 'balances']
    );
    // Secretary-only financial account creation
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->post(
        '/financial-accounts',
        [FinancialAccountController::class, 'store']
    );
    // Secretary-only inventory report
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->get(
        '/reports/inventory',
        [InventoryReportController::class, 'index']
    );
    Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->get(
        '/reports/inventory-movements',
        [InventoryMovementReportController::class, 'index']
    );
    // Secretary-only purchase returns
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('purchase-returns')
    ->group(function () {
        Route::get('/', [PurchaseReturnController::class, 'index']);
        Route::post('/', [PurchaseReturnController::class, 'store']);
        Route::get('/{id}', [PurchaseReturnController::class, 'show']);
    });
    // Secretary-only sale returns
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('sale-returns')
    ->group(function () {
        Route::get('/', [SaleReturnController::class, 'index']);
        Route::post('/', [SaleReturnController::class, 'store']);
        Route::get('/{id}', [SaleReturnController::class, 'show']);
    });
    // Secretary-only purchase return settlements
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('purchase-return-settlements')
    ->group(function () {
        Route::get('/', [PurchaseReturnSettlementController::class, 'index']);
        Route::post('/', [PurchaseReturnSettlementController::class, 'store']);
    });

// Secretary-only sale return settlements
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('sale-return-settlements')
    ->group(function () {
        Route::get('/', [SaleReturnSettlementController::class, 'index']);
        Route::post('/', [SaleReturnSettlementController::class, 'store']);
    });
    // Expense Categories
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('expense-categories')
    ->group(function () {
        Route::get('/', [ExpenseCategoryController::class, 'index']);
        Route::post('/', [ExpenseCategoryController::class, 'store']);
        Route::get('/{id}', [ExpenseCategoryController::class, 'show']);
        Route::put('/{id}', [ExpenseCategoryController::class, 'update']);
        Route::patch('/{id}', [ExpenseCategoryController::class, 'update']);
        Route::delete('/{id}', [ExpenseCategoryController::class, 'destroy']);
    });

// Expenses
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('expenses')
    ->group(function () {
        Route::get('/', [ExpenseController::class, 'index']);
        Route::post('/', [ExpenseController::class, 'store']);
        Route::get('/{id}', [ExpenseController::class, 'show']);
    });
    // Secretary-only cash transfers
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('cash-transfers')
    ->group(function () {
        Route::get('/', [CashTransferController::class, 'index']);
        Route::post('/', [CashTransferController::class, 'store']);
        Route::get('/{id}', [CashTransferController::class, 'show']);
    });
    // Daily Book Notes - Secretary Only
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('daily-book-notes')
    ->controller(DailyBookNoteController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{dailyBookNote}', 'show');
        Route::put('/{dailyBookNote}', 'update');
        Route::patch('/{dailyBookNote}', 'update');
        Route::delete('/{dailyBookNote}', 'destroy');
    });
// Document Management - Secretary Only
Route::middleware(['auth:sanctum', 'role:Secretary'])
    ->prefix('documents')
    ->controller(DocumentController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{document}', 'show');
        Route::get('/{document}/download', 'download');
        Route::delete('/{document}', 'destroy');
    });
    // Customer Portal - Customer Only
Route::middleware(['auth:sanctum', 'role:Customer'])
    ->prefix('customer')
    ->group(function () {
        Route::get('/profile', [CustomerPortalController::class, 'profile']);
        Route::get('/balance', [CustomerPortalController::class, 'balance']);
        Route::get('/transactions', [CustomerPortalController::class, 'transactions']);
    });