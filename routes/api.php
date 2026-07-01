<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HygieneReportController;
use App\Http\Controllers\Api\InternalOrderController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PurchaseNeedController;
use App\Http\Controllers\Api\PlanningController;
use App\Http\Controllers\Api\PointDeVenteController;
use App\Http\Controllers\Api\ProductController;

use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockForecastController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;


Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/password/reset', [AuthController::class, 'resetPasswordWithToken']);

Route::middleware(['jwt.auth'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/profile', [AuthController::class, 'updateProfile']); // method spoofing for FormData uploads
    Route::put('/reset-password', [AuthController::class, 'resetPassword']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::get('/comments', [CommentController::class, 'index']);
    Route::post('/comments', [CommentController::class, 'store']);
    Route::put('/comments/{comment}', [CommentController::class, 'update']);
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);
    Route::post('/chatbot/ask', [ChatbotController::class, 'ask']);

    Route::get('/roles', [UserController::class, 'roles']);
    Route::get('/caissiers', [UserController::class, 'getCaissiers']);
    Route::put('/users/{user}/assign-pdv', [UserController::class, 'assignPointDeVente']);

    Route::get('/dashboard', [DashboardController::class, 'index']);


    Route::middleware('role:SUPER_ADMIN')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::post('/users/{user}', [UserController::class, 'update']); 

        Route::apiResource('points-de-vente', PointDeVenteController::class);
        Route::get('/airports', [PointDeVenteController::class, 'airports']);

        Route::get('/users/check-email', [UserController::class, 'checkEmail']);
    });


    Route::middleware('role:RESPONSABLE_FB,SUPER_ADMIN')->group(function () {
        Route::put('/caissiers/{user}/status', [UserController::class, 'updateCaissierStatus']);
        Route::put('/users/{user}/caissier', [UserController::class, 'updateCaissier']);
        Route::delete('/users/{user}/caissier', [UserController::class, 'deleteCaissier']);
    });


    Route::middleware('role:SUPER_ADMIN,RESPONSABLE_FB,CAISSIER')->group(function () {
        Route::get('/caissier', [UserController::class, 'listCaissiers']);
        Route::apiResource('plannings', PlanningController::class);
        Route::post('/plannings/bulk', [PlanningController::class, 'bulkStore']);
        Route::get('/points-de-vente', [PointDeVenteController::class, 'index']);
    });



    Route::middleware('role:CHEF_CUISINE,SUPER_ADMIN')->group(function () {
        Route::put('/products/{product}/recipe', [ProductController::class, 'setRecipe']);

        Route::apiResource('menus', MenuController::class);
        Route::get('/menus/current-week', [MenuController::class, 'currentWeek']);
        Route::post('/menus/{menu}/submit', [MenuController::class, 'submit']);
        Route::post('/menus/{menu}/clone', [MenuController::class, 'clone']);
    });

    Route::middleware('role:CHEF_CUISINE,CHEF_MAGASIN,SUPER_ADMIN')->group(function () {
        Route::get('/purchase-needs', [PurchaseNeedController::class, 'index']);
        Route::get('/purchase-needs/{purchaseNeed}', [PurchaseNeedController::class, 'show']);
    });


    Route::middleware('role:CHEF_CUISINE,CHEF_MAGASIN,SUPER_ADMIN,RESPONSABLE_FB')->group(function () {
        Route::apiResource('stocks', StockController::class)->only(['index', 'show']);
        Route::get('/stocks/{stock}/movements', [StockController::class, 'movements']);
    });


    Route::middleware('role:CHEF_MAGASIN,SUPER_ADMIN')->group(function () {
        Route::post('/stocks/{stock}/movements', [StockController::class, 'addMovement']);
        Route::put('/stocks/{stock}/threshold', [StockController::class, 'updateThreshold']);
    });


    Route::middleware('role:CHEF_CUISINE,CHEF_MAGASIN,RESPONSABLE_FB,SUPER_ADMIN')->group(function () {
        Route::post('/products/by-categories', [InternalOrderController::class, 'getProductsByCategories']);
        Route::post('/internal-orders', [InternalOrderController::class, 'store']);
        Route::delete('/internal-orders/{internalOrder}', [InternalOrderController::class, 'destroy']);
    });


    Route::middleware('role:CHEF_CUISINE,CHEF_MAGASIN,RESPONSABLE_ACHAT,SUPER_ADMIN')->group(function () {
        Route::apiResource('products', ProductController::class);
        Route::put('/products/{product}/toggle-active', [ProductController::class, 'toggleActive']);
    });

    Route::middleware('role:CHEF_CUISINE,CHEF_MAGASIN,SUPER_ADMIN')->group(function () {
        Route::put('/internal-orders/{internalOrder}/status', [InternalOrderController::class, 'updateStatus']);
        Route::put('/internal-orders/{internalOrder}/items/{item}/fulfill', [InternalOrderController::class, 'fulfillItem']);
    });


    Route::middleware('role:RESPONSABLE_HYGIENE,SUPER_ADMIN')->group(function () {
        Route::get('/hygiene-reports/export', [HygieneReportController::class, 'export']);
        Route::apiResource('hygiene-reports', HygieneReportController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::put('/products/{product}/hygiene', [ProductController::class, 'hygieneUpdate']);
    });


    Route::middleware('role:RESPONSABLE_ACHAT,SUPER_ADMIN')->group(function () {
        Route::get('/stock-forecast', [StockForecastController::class, 'forecast']);
        Route::get('/stock-anomalies', [StockForecastController::class, 'anomalies']);
        Route::get('/stock-recommendations', [StockForecastController::class, 'recommendations']);
        Route::get('/stock-ai-report', [StockForecastController::class, 'aiReport']);
        Route::put('/products/{product}/approve', [ProductController::class, 'approveProduct']);
        Route::post('/categories', [ProductController::class, 'storeCategory']);
        Route::put('/categories/{category}', [ProductController::class, 'updateCategory']);
        Route::delete('/categories/{category}', [ProductController::class, 'destroyCategory']);
    });


    Route::middleware('role:CAISSIER,SUPER_ADMIN')->group(function () {

    });


    Route::middleware('role:CHEF_CUISINE,CHEF_MAGASIN,RESPONSABLE_FB,RESPONSABLE_ACHAT,RESPONSABLE_HYGIENE,SUPER_ADMIN')->group(function () {
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::get('/categories', [ProductController::class, 'categories']);
        Route::get('/stocks/alerts/low', [StockController::class, 'lowStockAlerts']);
        Route::get('/stocks/alerts/expired', [StockController::class, 'expiredProducts']);
    });

    Route::middleware('role:CHEF_CUISINE,CHEF_MAGASIN,RESPONSABLE_FB,SUPER_ADMIN')->group(function () {
        Route::get('/internal-orders', [InternalOrderController::class, 'index']);
        Route::get('/internal-orders/{internalOrder}', [InternalOrderController::class, 'show']);
    });
});
