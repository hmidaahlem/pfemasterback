<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HygieneReport;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class HygieneReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = HygieneReport::with('product', 'inspector');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'allergens_verified' => 'required|boolean',
            'expiration_verified' => 'required|boolean',
            'status' => 'required|in:conforme,non_conforme,en_cours',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $report = HygieneReport::create([
            ...$request->only(['product_id', 'allergens_verified', 'expiration_verified', 'status', 'remarks']),
            'inspected_by' => auth()->id(),
        ]);

        // Notify relevant roles on non-conforme reports
        if ($request->status === 'non_conforme') {
            $this->notifyRoles(
                'Rapport d\'hygiène non conforme',
                "Le produit #{$request->product_id} a été signalé non conforme.",
                'alert',
                ['report_id' => $report->id, 'product_id' => $request->product_id]
            );
        }

        return response()->json([
            'message' => 'Rapport d\'hygiène créé.',
            'report' => $report->load('product', 'inspector'),
        ], 201);
    }

    public function show(HygieneReport $hygieneReport): JsonResponse
    {
        return response()->json($hygieneReport->load('product', 'inspector'));
    }

    public function update(Request $request, HygieneReport $hygieneReport): JsonResponse
    {
        $request->validate([
            'allergens_verified' => 'sometimes|boolean',
            'expiration_verified' => 'sometimes|boolean',
            'status' => 'sometimes|in:conforme,non_conforme,en_cours',
            'remarks' => 'sometimes|nullable|string|max:1000',
        ]);

        $oldStatus = $hygieneReport->status;
        $hygieneReport->update($request->only([
            'allergens_verified', 'expiration_verified', 'status', 'remarks',
        ]));

        // Notify if status changed to non_conforme
        if ($hygieneReport->status === 'non_conforme' && $oldStatus !== 'non_conforme') {
            $this->notifyRoles(
                'Non-conformité hygiène',
                "Le produit #{$hygieneReport->product_id} est désignalé non conforme.",
                'alert',
                ['report_id' => $hygieneReport->id, 'product_id' => $hygieneReport->product_id]
            );
        }

        return response()->json([
            'message' => 'Rapport mis à jour.',
            'report' => $hygieneReport->fresh()->load('product', 'inspector'),
        ]);
    }

    public function destroy(HygieneReport $hygieneReport): JsonResponse
    {
        $hygieneReport->delete();

        return response()->json(['message' => 'Rapport supprimé.']);
    }

    public function export(): \Illuminate\Http\Response
    {
        $reports = HygieneReport::with('product', 'inspector')
            ->orderBy('created_at', 'desc')
            ->get();

        $csv = "id,product,inspector,allergens_verified,expiration_verified,status,remarks,created_at\n";
        foreach ($reports as $r) {
            $productName = str_replace('"', '""', $r->product?->name ?? 'N/A');
            $inspectorName = str_replace('"', '""', ($r->inspector?->first_name ?? '').' '.($r->inspector?->last_name ?? ''));
            $remarks = str_replace('"', '""', $r->remarks ?? '');
            $csv .= "\"{$r->id}\",\"{$productName}\",\"{$inspectorName}\",\"{$r->allergens_verified}\",\"{$r->expiration_verified}\",\"{$r->status}\",\"{$remarks}\",\"{$r->created_at}\"\n";
        }

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="hygiene-reports-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    private function notifyRoles(string $title, string $message, string $type, array $data = []): void
    {
        $roles = ['CHEF_MAGASIN', 'CHEF_CUISINE', 'RESPONSABLE_ACHAT'];
        $users = User::whereHas('role', function ($q) use ($roles) {
            $q->whereIn('name', $roles);
        })->get();

        foreach ($users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'is_read' => false,
                'data' => $data,
            ]);
        }
    }
}
