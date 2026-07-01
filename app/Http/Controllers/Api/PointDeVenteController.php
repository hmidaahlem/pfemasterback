<?php

namespace App\Http\Controllers\Api;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator; // ✅ IMPORTANT
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\Role;
use App\Models\Airport;
use App\Models\Notification;
use App\Models\PointDeVente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class PointDeVenteController extends Controller
{
  public function index()
  {
      $user = auth()->user();
      $query = PointDeVente::with(['airport', 'responsableFb']);
      if ($user && $user->role?->name === 'RESPONSABLE_FB') {
          $query->where('responsable_fb_id', $user->id);
          // If no specific PDV is assigned to this user, fall back to showing all PDVs so the dropdown is not empty
          if ($query->count() === 0) {
              $query = PointDeVente::with(['airport', 'responsableFb']);
          }
      }
      return $query->get();
  }

public function store(Request $request): JsonResponse
{
    $request->validate([
        'name' => 'required|string|max:255',
        'airport_id' => 'required|exists:airports,id',
        'responsable_fb_id' => 'nullable|exists:users,id',
        'location' => 'nullable|in:AIRSIDE,LANDSIDE',
    ]);

    try {

        $pdv = DB::transaction(function () use ($request) {

            $responsableId = $request->responsable_fb_id;

            if ($responsableId) {

                // 1. Get user
                $user = User::with('role')->find($responsableId);

                // 2. Check role
                if (!$user || !$user->role || $user->role->name !== 'RESPONSABLE_FB') {
                    abort(422, 'Selected user is not a RESPONSABLE_FB.');
                }

                // 3. Max 2 PDV per responsible (STRICT RULE)
                $count = PointDeVente::where('responsable_fb_id', $responsableId)->count();

                if ($count >= 2) {
                    abort(422, 'This responsible FB is already assigned to maximum 2 points de vente.');
                }
            }

            return PointDeVente::create([
                'name' => $request->name,
                'airport_id' => $request->airport_id,
                'responsable_fb_id' => $responsableId,
                'location' => $request->location,
            ]);
        });

        // Notify the assigned Responsable FB
        if ($request->responsable_fb_id) {
            Notification::create([
                'user_id' => $request->responsable_fb_id,
                'title'   => 'Attribution — Point de vente',
                'message' => "Vous avez ete assigne(e) comme Responsable F&B du point de vente \"{$pdv->name}\".",
                'type'    => 'info',
                'is_read' => false,
                'data'    => ['pdv_id' => $pdv->id],
            ]);
        }

        return response()->json([
            'message' => 'Point de vente created successfully',
            'data' => $pdv->load(['airport', 'responsableFb'])
        ], 201);

    } catch (\Throwable $e) {

        Log::error('STORE ERROR', [
            'message' => $e->getMessage()
        ]);

        $status = ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) ? $e->getStatusCode() : 500;

        return response()->json([
            'error' => $e->getMessage()
        ], $status);
    }
}
    public function show(PointDeVente $pointDeVente): JsonResponse
    {
        return response()->json($pointDeVente->load('airport', 'users.role'));
    }
public function update(Request $request, $id)
{
    $pointDeVente = PointDeVente::find($id);

    if (!$pointDeVente) {
        return response()->json([
            'message' => 'Point de vente not found.'
        ], 404);
    }

    $request->validate([
        'name' => 'sometimes|string|max:255',
        'airport_id' => 'sometimes|exists:airports,id',
        'is_active' => 'sometimes|boolean',
        'responsable_fb_id' => 'nullable|exists:users,id',
        'location' => 'nullable|in:AIRSIDE,LANDSIDE',
    ]);

    $newResponsable = $request->input('responsable_fb_id');
    $oldResponsable = $pointDeVente->responsable_fb_id;

    if ($request->has('responsable_fb_id') && $newResponsable != $oldResponsable) {

        $count = PointDeVente::where('responsable_fb_id', $newResponsable)
            ->where('id', '!=', $pointDeVente->id)
            ->count();

        if ($count >= 2) {
            return response()->json([
                'message' => 'This responsable is already assigned to maximum 2 points de vente.'
            ], 422);
        }
    }

    $pointDeVente->update([
        'name' => $request->name ?? $pointDeVente->name,
        'airport_id' => $request->airport_id ?? $pointDeVente->airport_id,
        'is_active' => $request->is_active ?? $pointDeVente->is_active,
        'location' => $request->has('location') ? $request->location : $pointDeVente->location,
        'responsable_fb_id' => $request->has('responsable_fb_id')
            ? $newResponsable
            : $oldResponsable,
    ]);

    // Notify new Responsable FB if the assignment changed
    if ($request->has('responsable_fb_id') && $newResponsable && $newResponsable != $oldResponsable) {
        Notification::create([
            'user_id' => $newResponsable,
            'title'   => 'Attribution — Point de vente',
            'message' => "Vous avez ete assigne(e) comme Responsable F&B du point de vente \"{$pointDeVente->name}\".",
            'type'    => 'info',
            'is_read' => false,
            'data'    => ['pdv_id' => $pointDeVente->id],
        ]);
    }

    return response()->json([
        'message' => 'Updated successfully',
        'data' => $pointDeVente->fresh()->load(['airport', 'responsableFb'])
    ]);
}
public function destroy($id): JsonResponse
{
    $pdv = PointDeVente::find($id);

    if (!$pdv) {
        return response()->json([
            'message' => 'Point de vente not found'
        ], 404);
    }

    $pdv->delete();

    return response()->json([
        'message' => 'Deleted successfully',
        'id' => $id
    ]);
}

    public function airports(): JsonResponse
    {
        return response()->json(Airport::with('pointsDeVente')->get());
    }
}
