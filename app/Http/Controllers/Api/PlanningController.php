<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Planning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PlanningController extends Controller
{
    private function determineShiftFromTime(?string $startTime): string
    {
        if (! $startTime) {
            return 'MATIN';
        }

        $hour = (int) explode(':', $startTime)[0];

        if ($hour < 12) {
            return 'MATIN';
        }

        if ($hour < 17) {
            return 'APRES_MIDI';
        }

        return 'SOIR';
    }

    public function index(Request $request): JsonResponse
    {
        $query = Planning::with('caissier.role', 'pointDeVente', 'createdBy');

        if ($request->has('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        if (auth()->user()->role->name === 'CAISSIER') {
            $query->where('user_id', auth()->id());
        } elseif ($request->has('caissier_id')) {
            $query->where('user_id', $request->caissier_id);
        }

        if ($request->has('pdv_id')) {
            $query->where('pdv_id', $request->pdv_id);
        }

        return response()->json($query->orderBy('date')->paginate(30));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'caissier_id' => 'required|exists:users,id',
            'pdv_id' => 'nullable|exists:points_de_vente,id',
            'date' => 'required|date',
            'is_day_off' => 'sometimes|boolean',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'shift' => 'sometimes|in:MATIN,APRES_MIDI,SOIR',
            'day_status' => 'sometimes|in:ON,OFF,CONGE',
        ]);

        $date = $request->date;
        $caissierId = $request->caissier_id;
        $dayStatus = $request->day_status ?? ($request->boolean('is_day_off') ? 'OFF' : 'ON');
        $isDayOff = ($dayStatus === 'OFF' || $dayStatus === 'CONGE');
        $startTime = $isDayOff ? null : $request->start_time;
        $shift = $request->shift ?? $this->determineShiftFromTime($startTime);

        if (! $isDayOff) {
            if (! $request->pdv_id) {
                return response()->json(['message' => 'Le point de vente est obligatoire pour un shift actif.'], 422);
            }

            // Get existing shifts for the day and shift for max PDV limit
            $sameShiftShifts = Planning::where('user_id', $caissierId)
                ->where('date', $date)
                ->where('shift', $shift)
                ->where('is_day_off', false)
                ->get();

            // Enforce max 2 PDVs per shift
            $pdvIds = array_unique(array_merge($sameShiftShifts->pluck('pdv_id')->toArray(), [$request->pdv_id]));
            if (count($pdvIds) > 2) {
                return response()->json([
                    'message' => 'Un caissier ne peut pas être affecté à plus de 2 points de vente différents pour le même shift.',
                ], 422);
            }

            // Check for time overlaps against ALL shifts for the day
            $allDayShifts = Planning::where('user_id', $caissierId)
                ->where('date', $date)
                ->where('is_day_off', false)
                ->get();

            $start = $request->start_time;
            $end = $request->end_time;
            if ($start && $end) {
                if ($start === $end) {
                    return response()->json(['message' => "L'heure de fin doit être différente de l'heure de début."], 422);
                }

                $sStart = Carbon::parse($date.' '.$start);
                $sEnd = Carbon::parse($date.' '.$end);
                if ($sEnd->lessThanOrEqualTo($sStart)) {
                    $sEnd->addDay();
                }

                foreach ($allDayShifts as $s) {
                    if ($s->start_time && $s->end_time) {
                        $dbStart = Carbon::parse($s->date.' '.$s->start_time);
                        $dbEnd = Carbon::parse($s->date.' '.$s->end_time);
                        if ($dbEnd->lessThanOrEqualTo($dbStart)) {
                            $dbEnd->addDay();
                        }

                        if ($sStart->lessThan($dbEnd) && $dbStart->lessThan($sEnd)) {
                            return response()->json([
                                'message' => "Conflit d'horaires : ce shift chevauche une affectation existante.",
                            ], 422);
                        }
                    }
                }
            }
        } else {
            // Delete active shifts on the same day if setting as rest day
            Planning::where('user_id', $caissierId)->where('date', $date)->delete();
        }

        $planning = Planning::create([
            'user_id' => $request->caissier_id,
            'pdv_id' => $isDayOff ? null : $request->pdv_id,
            'date' => $request->date,
            'is_day_off' => $isDayOff,
            'shift' => $shift,
            'day_status' => $dayStatus,
            'start_time' => $isDayOff ? null : $request->start_time,
            'end_time' => $isDayOff ? null : $request->end_time,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Planning créé.',
            'planning' => $planning->load('caissier', 'pointDeVente'),
        ], 201);
    }

    public function update(Request $request, Planning $planning): JsonResponse
    {
        $request->validate([
            'pdv_id' => 'nullable|exists:points_de_vente,id',
            'date' => 'sometimes|date',
            'is_day_off' => 'sometimes|boolean',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'shift' => 'sometimes|in:MATIN,APRES_MIDI,SOIR',
            'day_status' => 'sometimes|in:ON,OFF,CONGE',
        ]);

        $date = $request->date ?? $planning->date;
        $caissierId = $planning->user_id;
        $dayStatus = $request->day_status ?? ($request->has('is_day_off') ? ($request->boolean('is_day_off') ? 'OFF' : 'ON') : $planning->day_status);
        $isDayOff = ($dayStatus === 'OFF' || $dayStatus === 'CONGE');
        $start = $request->start_time ?? $planning->start_time;
        $end = $request->end_time ?? $planning->end_time;
        if ($request->has('shift')) {
            $shift = $request->shift;
        } elseif ($request->has('start_time')) {
            $shift = $this->determineShiftFromTime($start);
        } else {
            $shift = $planning->shift;
        }
        $pdvId = $request->has('pdv_id') ? $request->pdv_id : $planning->pdv_id;

        if (! $isDayOff) {
            if (! $pdvId) {
                return response()->json(['message' => 'Le point de vente est obligatoire pour un shift actif.'], 422);
            }

            // Get other shifts for the day and shift for max PDV limit
            $sameShiftShifts = Planning::where('user_id', $caissierId)
                ->where('date', $date)
                ->where('shift', $shift)
                ->where('id', '!=', $planning->id)
                ->where('is_day_off', false)
                ->get();

            // Enforce max 2 PDVs per shift
            $pdvIds = array_unique(array_merge($sameShiftShifts->pluck('pdv_id')->toArray(), [$pdvId]));
            if (count($pdvIds) > 2) {
                return response()->json([
                    'message' => 'Un caissier ne peut pas être affecté à plus de 2 points de vente différents pour le même shift.',
                ], 422);
            }

            // Check for time overlaps against ALL shifts for the day
            $allDayShifts = Planning::where('user_id', $caissierId)
                ->where('date', $date)
                ->where('id', '!=', $planning->id)
                ->where('is_day_off', false)
                ->get();

            if ($start && $end) {
                if ($start === $end) {
                    return response()->json(['message' => "L'heure de fin doit être différente de l'heure de début."], 422);
                }

                $sStart = Carbon::parse($date.' '.$start);
                $sEnd = Carbon::parse($date.' '.$end);
                if ($sEnd->lessThanOrEqualTo($sStart)) {
                    $sEnd->addDay();
                }

                foreach ($allDayShifts as $s) {
                    if ($s->start_time && $s->end_time) {
                        $dbStart = Carbon::parse($s->date.' '.$s->start_time);
                        $dbEnd = Carbon::parse($s->date.' '.$s->end_time);
                        if ($dbEnd->lessThanOrEqualTo($dbStart)) {
                            $dbEnd->addDay();
                        }

                        if ($sStart->lessThan($dbEnd) && $dbStart->lessThan($sEnd)) {
                            return response()->json([
                                'message' => "Conflit d'horaires : ce shift chevauche une affectation existante.",
                            ], 422);
                        }
                    }
                }
            }
        }

        $planning->update([
            'pdv_id' => $isDayOff ? null : $pdvId,
            'date' => $date,
            'is_day_off' => $isDayOff,
            'shift' => $shift,
            'day_status' => $dayStatus,
            'start_time' => $isDayOff ? null : $start,
            'end_time' => $isDayOff ? null : $end,
        ]);

        return response()->json([
            'message' => 'Planning mis à jour.',
            'planning' => $planning->fresh()->load('caissier', 'pointDeVente'),
        ]);
    }

    public function destroy(Planning $planning): JsonResponse
    {
        $planning->delete();

        return response()->json(['message' => 'Planning supprimé.']);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $request->validate([
            'plannings' => 'required|array|min:1',
            'plannings.*.caissier_id' => 'required|exists:users,id',
            'plannings.*.pdv_id' => 'nullable|exists:points_de_vente,id',
            'plannings.*.date' => 'required|date',
            'plannings.*.is_day_off' => 'sometimes|boolean',
            'plannings.*.start_time' => 'nullable|date_format:H:i',
            'plannings.*.end_time' => 'nullable|date_format:H:i',
            'plannings.*.shift' => 'sometimes|in:MATIN,APRES_MIDI,SOIR',
            'plannings.*.day_status' => 'sometimes|in:ON,OFF,CONGE',
        ]);

        $incoming = $request->plannings;

        // 1. Group by week to validate full-week constraint
        $firstPlan = $incoming[0];
        $dateTime = new \DateTime($firstPlan['date']);
        $startOfWeek = clone $dateTime;
        $startOfWeek->modify('Monday this week');
        $startOfWeekStr = $startOfWeek->format('Y-m-d');
        $endOfWeek = clone $dateTime;
        $endOfWeek->modify('Sunday this week');
        $endOfWeekStr = $endOfWeek->format('Y-m-d');

        // Validate that ALL planning dates fall inside this exact calendar week
        foreach ($incoming as $plan) {
            $planDate = $plan['date'];
            if ($planDate < $startOfWeekStr || $planDate > $endOfWeekStr) {
                return response()->json([
                    'message' => 'Toutes les affectations doivent appartenir à la même semaine calendrier (du '.$startOfWeekStr.' au '.$endOfWeekStr.').',
                ], 422);
            }
        }

        // Validate cashier daily limits and overlapping shifts
        $byCashier = [];
        foreach ($incoming as $plan) {
            $byCashier[$plan['caissier_id']][] = $plan;
        }

        foreach ($byCashier as $cashierId => $plans) {
            $byDate = [];
            foreach ($plans as $plan) {
                $byDate[$plan['date']][] = $plan;
            }

            foreach ($byDate as $date => $dayPlans) {
                // Group by shift
                $byShift = [];
                foreach ($dayPlans as $p) {
                    $dayStatus = $p['day_status'] ?? (! empty($p['is_day_off']) ? 'OFF' : 'ON');
                    if ($dayStatus === 'ON') {
                        $shiftVal = $p['shift'] ?? $this->determineShiftFromTime($p['start_time'] ?? null);
                        $byShift[$shiftVal][] = $p;
                    }
                }

                foreach ($byShift as $sVal => $activeShifts) {
                    // Enforce max 2 PDVs per shift
                    $pdvIds = array_unique(array_filter(array_column($activeShifts, 'pdv_id')));
                    if (count($pdvIds) > 2) {
                        return response()->json([
                            'message' => "Un caissier ne peut pas être affecté à plus de 2 points de vente dans le shift {$sVal} le même jour ({$date}).",
                        ], 422);
                    }

                    // Check for overlapping shifts inside the incoming request
                    $shiftsCount = count($activeShifts);
                    for ($i = 0; $i < $shiftsCount; $i++) {
                        for ($j = $i + 1; $j < $shiftsCount; $j++) {
                            $s1 = $activeShifts[$i];
                            $s2 = $activeShifts[$j];

                            if (! empty($s1['start_time']) && ! empty($s1['end_time']) && ! empty($s2['start_time']) && ! empty($s2['end_time'])) {
                                if ($s1['start_time'] === $s2['end_time']) {
                                    continue;
                                }

                                $s1Start = Carbon::parse($date.' '.$s1['start_time']);
                                $s1End = Carbon::parse($date.' '.$s1['end_time']);
                                if ($s1End->lessThanOrEqualTo($s1Start)) {
                                    $s1End->addDay();
                                }

                                $s2Start = Carbon::parse($date.' '.$s2['start_time']);
                                $s2End = Carbon::parse($date.' '.$s2['end_time']);
                                if ($s2End->lessThanOrEqualTo($s2Start)) {
                                    $s2End->addDay();
                                }

                                if ($s1Start->lessThan($s2End) && $s2Start->lessThan($s1End)) {
                                    return response()->json([
                                        'message' => "Conflit d'horaires : Deux shifts se chevauchent dans le shift {$sVal} le {$date} pour le même caissier.",
                                    ], 422);
                                }
                            }
                        }
                    }
                }
            }
        }

        $created = DB::transaction(function () use ($byCashier, $startOfWeekStr, $endOfWeekStr, $incoming) {
            foreach ($byCashier as $cashierId => $plans) {
                // Standard clean rewrite: delete pre-existing plannings for these cashiers in this target week
                Planning::where('user_id', $cashierId)
                    ->whereBetween('date', [$startOfWeekStr, $endOfWeekStr])
                    ->delete();
            }

            // Perform bulk insertions
            $created = [];
            foreach ($incoming as $plan) {
                $dayStatus = $plan['day_status'] ?? (! empty($plan['is_day_off']) ? 'OFF' : 'ON');
                $isDayOff = ($dayStatus === 'OFF' || $dayStatus === 'CONGE');
                $shift = $plan['shift'] ?? $this->determineShiftFromTime($plan['start_time'] ?? null);

                $created[] = Planning::create([
                    'user_id' => $plan['caissier_id'],
                    'pdv_id' => $isDayOff ? null : ($plan['pdv_id'] ?? null),
                    'date' => $plan['date'],
                    'is_day_off' => $isDayOff,
                    'shift' => $shift,
                    'day_status' => $dayStatus,
                    'start_time' => $isDayOff ? null : ($plan['start_time'] ?? null),
                    'end_time' => $isDayOff ? null : ($plan['end_time'] ?? null),
                    'created_by' => auth()->id(),
                ]);
            }

            return $created;
        });

        return response()->json([
            'message' => count($created).' affectations enregistrées pour la semaine.',
            'plannings' => $created,
        ], 201);
    }
}
