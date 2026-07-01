<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($notifications);
    }

    public function unreadCount(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $count = $user->notifications()->where('is_read', false)->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($notification->user_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marquée comme lue.']);
    }

    public function markAllAsRead(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $user->notifications()->where('is_read', false)->update(['is_read' => true]);

        return response()->json(['message' => 'Toutes les notifications marquées comme lues.']);
    }
}
