<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StockForecastController extends Controller
{

    public function forecast(): JsonResponse
    {
        $products = Product::with('stock')->where('is_active', true)->get();
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $forecastData = [];

        foreach ($products as $product) {
            $currentStock = $product->stock ? $product->stock->quantity : 0;
            $unit = $product->stock ? $product->stock->unit : 'unit';

            $totalWithdrawn = StockMovement::whereHas('stock', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })
                ->where('type', 'out')
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->sum('quantity');

            $daysDiff = Carbon::parse($product->created_at)->diffInDays(Carbon::now());
            $divisor = $daysDiff > 0 ? min(30, $daysDiff) : 1;

            $dailyAvg = round($totalWithdrawn / $divisor, 2);
            $daysLeft = null;
            $status = 'STABLE';

            if ($dailyAvg > 0) {
                $daysLeft = round($currentStock / $dailyAvg, 1);
                if ($daysLeft <= 3) {
                    $status = 'CRITIQUE';
                } elseif ($daysLeft <= 7) {
                    $status = 'MOYEN';
                }
            } else {
                $daysLeft = 999; // Unlimited if no consumption
            }

            $forecastData[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'type' => $product->type,
                'current_stock' => $currentStock,
                'unit' => $unit,
                'daily_average' => $dailyAvg,
                'days_left' => $daysLeft === 999 ? '∞' : $daysLeft,
                'status' => $status,
            ];
        }

        return response()->json($forecastData);
    }


    public function anomalies(): JsonResponse
    {
        $products = Product::with('stock')->where('is_active', true)->get();
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $todayStart = Carbon::today();

        $anomalies = [];

        foreach ($products as $product) {
            // Get 30-day average daily out movement
            $totalWithdrawn = StockMovement::whereHas('stock', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })
                ->where('type', 'out')
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->sum('quantity');

            $daysDiff = Carbon::parse($product->created_at)->diffInDays(Carbon::now());
            $divisor = $daysDiff > 0 ? min(30, $daysDiff) : 1;

            $dailyAvg = $totalWithdrawn / $divisor;

            // Get today's out movements
            $todayWithdrawn = StockMovement::whereHas('stock', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })
                ->where('type', 'out')
                ->where('created_at', '>=', $todayStart)
                ->sum('quantity');

            if ($dailyAvg > 0.5 && $todayWithdrawn > ($dailyAvg * 3)) {
                $anomalies[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'daily_average' => round($dailyAvg, 2),
                    'today_consumption' => $todayWithdrawn,
                    'ratio' => round($todayWithdrawn / $dailyAvg, 1),
                    'message' => "Surconsommation détectée: la quantité sortie aujourd'hui est ".round($todayWithdrawn / $dailyAvg, 1).' fois supérieure à la moyenne habituelle.',
                ];
            }
        }

        return response()->json($anomalies);
    }

    public function recommendations(): JsonResponse
    {
        $products = Product::with('stock')->where('is_active', true)->get();
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $recommendations = [];

        foreach ($products as $product) {
            $currentStock = $product->stock ? $product->stock->quantity : 0;
            $unit = $product->stock ? $product->stock->unit : 'unit';

            $totalWithdrawn = StockMovement::whereHas('stock', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })
                ->where('type', 'out')
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->sum('quantity');

            $daysDiff = Carbon::parse($product->created_at)->diffInDays(Carbon::now());
            $divisor = $daysDiff > 0 ? min(30, $daysDiff) : 1;

            $dailyAvg = $totalWithdrawn / $divisor;

            if ($dailyAvg > 0) {
                $daysLeft = $currentStock / $dailyAvg;
                // If stock runs out within 10 days
                if ($daysLeft <= 10) {
                    // Recommend quantities to cover 30 days of consumption
                    $recommendedQty = ceil(($dailyAvg * 30) - $currentStock);
                    if ($recommendedQty > 0) {
                        $recommendations[] = [
                            'product_id' => $product->id,
                            'name' => $product->name,
                            'type' => $product->type,
                            'current_stock' => $currentStock,
                            'unit' => $unit,
                            'days_left' => round($daysLeft, 1),
                            'recommended_qty' => $recommendedQty,
                            'reason' => 'Le stock actuel de '.round($currentStock)." {$unit} risque d'être épuisé d'ici ".round($daysLeft, 1).' jours.',
                        ];
                    }
                }
            }
        }

        return response()->json($recommendations);
    }

    public function aiReport(): JsonResponse
    {
        $recommendationsResponse = $this->recommendations();
        $recommendations = json_decode($recommendationsResponse->getContent(), true);

        if (empty($recommendations)) {
            return response()->json([
                'success' => true,
                'report' => "Le stock est actuellement stable. Il n'y a aucune recommandation critique d'achat pour le moment.",
                'source' => 'system',
            ]);
        }

        $context = "Données actuelles de stock critique et recommandations d'achats :\n";
        foreach ($recommendations as $rec) {
            $context .= "- {$rec['name']} ({$rec['type']}) : Stock actuel = {$rec['current_stock']} {$rec['unit']}. ".
                        "S'épuise dans {$rec['days_left']} jours. ".
                        "Quantité recommandée à commander: {$rec['recommended_qty']} {$rec['unit']}.\n";
        }

     //   $openaiKey = config('services.openai.key');
        $groqKey = config('services.groq.key');

        $systemPrompt = "Tu es un expert en gestion de stock et logistique pour un restaurant (AeroServe).\n".
                        'Analyse les données suivantes et génère un résumé narratif professionnel et concis (environ 3 à 5 phrases) pour le responsable des achats. '.
                        'Mets en évidence les urgences absolues. Utilise un ton professionnel en français.';


        if ($groqKey && $groqKey !== 'null' && ! empty($groqKey)) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$groqKey}",
                    'Content-Type' => 'application/json',
                ])->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => 'llama-3.1-8b-instant',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $context],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 300,
                ]);

                if ($response->successful()) {
                    return response()->json([
                        'success' => true,
                        'report' => trim($response->json('choices.0.message.content')),
                        'source' => 'groq',
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Groq Stock AI error: '.$e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'report' => "Veuillez vérifier les alertes de stock manuel. L'IA est actuellement indisponible.",
            'source' => 'system',
        ]);
    }
}
