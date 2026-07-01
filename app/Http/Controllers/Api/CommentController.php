<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\InternalOrder;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'commentable_type' => 'required|in:internal_order,product',
            'commentable_id' => 'required|integer',
            'body' => 'required|string|max:1000',
        ]);

        $typeMap = [
            'internal_order' => InternalOrder::class,
            'product' => Product::class,
        ];

        // Strict F&B order comment security checks
        if ($request->commentable_type === 'internal_order') {
            $order = InternalOrder::find($request->commentable_id);
            if (! $order) {
                return response()->json(['message' => 'Commande introuvable.'], 404);
            }
            $userRole = auth()->user()->role?->name;
            if ($userRole === 'RESPONSABLE_FB') {
                return response()->json([
                    'message' => 'Le Responsable F&B n\'est pas autorisé à ajouter des commentaires sur les commandes.',
                ], 403);
            }
            if (! in_array($userRole, ['CHEF_CUISINE', 'CHEF_MAGASIN', 'SUPER_ADMIN'])) {
                return response()->json([
                    'message' => 'Seuls le Chef Cuisine et le Chef Magasin concernés sont autorisés à commenter cette commande.',
                ], 403);
            }
            // Participant-based check: user must be creator or assignee of this order
            $allowedUserIds = array_filter([$order->created_by, $order->assigned_to]);
            if (! in_array(auth()->id(), $allowedUserIds) && $userRole !== 'SUPER_ADMIN') {
                return response()->json([
                    'message' => 'Vous n\'êtes pas un participant à cette commande.',
                ], 403);
            }
        }

        // Product comment security: only admins, responsible_achat, chefs, or product creator can post
        if ($request->commentable_type === 'product') {
            $product = Product::find($request->commentable_id);
            if (! $product) {
                return response()->json(['message' => 'Produit introuvable.'], 404);
            }
            $userRole = auth()->user()->role?->name;
            $isCreator = $product->created_by === auth()->id();
            $hasPrivilege = in_array($userRole, ['SUPER_ADMIN', 'RESPONSABLE_ACHAT', 'CHEF_CUISINE', 'CHEF_MAGASIN']);
            if (! $isCreator && ! $hasPrivilege) {
                return response()->json([
                    'message' => 'Vous n\'êtes pas autorisé à commenter ce produit.',
                ], 403);
            }
        }

        $comment = Comment::create([
            'user_id' => auth()->id(),
            'commentable_type' => $typeMap[$request->commentable_type],
            'commentable_id' => $request->commentable_id,
            'body' => $request->body,
        ]);

        // Send notifications
        $userName = auth()->user()->first_name.' '.auth()->user()->last_name;
        if ($request->commentable_type === 'internal_order') {
            $order = InternalOrder::find($request->commentable_id);
            if ($order) {
                // Notify creator (F&B) if not current user
                if ($order->created_by && $order->created_by !== auth()->id()) {
                    Notification::create([
                        'user_id' => $order->created_by,
                        'title' => 'New Comment on Order',
                        'message' => "{$userName} added a comment on Order #{$order->id}: \"{$comment->body}\"",
                        'type' => 'comment',
                        'is_read' => false,
                        'data' => ['order_id' => $order->id],
                    ]);
                }
                // Notify assignee (Chef Cuisine / Chef Magasin) if not current user
                if ($order->assigned_to && $order->assigned_to !== auth()->id()) {
                    Notification::create([
                        'user_id' => $order->assigned_to,
                        'title' => 'New Comment on Order',
                        'message' => "{$userName} added a comment on Order #{$order->id}: \"{$comment->body}\"",
                        'type' => 'comment',
                        'is_read' => false,
                        'data' => ['order_id' => $order->id],
                    ]);
                }
            }
        } elseif ($request->commentable_type === 'product') {
            $product = Product::find($request->commentable_id);
            if ($product) {
                // Notify product creator if not current user
                if ($product->created_by && $product->created_by !== auth()->id()) {
                    Notification::create([
                        'user_id' => $product->created_by,
                        'title' => 'New Comment on Product',
                        'message' => "{$userName} added a comment on Product \"{$product->name}\": \"{$comment->body}\"",
                        'type' => 'comment',
                        'is_read' => false,
                        'data' => ['product_id' => $product->id],
                    ]);
                }

                // Notify Responsable Achat if not current user
                $purchasingUsers = User::whereHas('role', fn ($q) => $q->where('name', 'RESPONSABLE_ACHAT'))->get();
                foreach ($purchasingUsers as $pu) {
                    if ($pu->id !== auth()->id()) {
                        Notification::create([
                            'user_id' => $pu->id,
                            'title' => 'New Comment on Product',
                            'message' => "{$userName} added a comment on Product \"{$product->name}\": \"{$comment->body}\"",
                            'type' => 'comment',
                            'is_read' => false,
                            'data' => ['product_id' => $product->id],
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'message' => 'Commentaire ajouté.',
            'comment' => $comment->load('user'),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'commentable_type' => 'required|in:internal_order,product',
            'commentable_id' => 'required|integer',
        ]);

        $typeMap = [
            'internal_order' => InternalOrder::class,
            'product' => Product::class,
        ];

        $user = auth()->user();
        $role = $user->role?->name;

        $comments = Comment::where('commentable_type', $typeMap[$request->commentable_type])
            ->where('commentable_id', $request->commentable_id)
            ->with('user')
            ->orderBy('created_at', 'desc');

        // Role-based filtering for internal order comments
        if ($request->commentable_type === 'internal_order' && $role !== 'SUPER_ADMIN') {
            $order = InternalOrder::find($request->commentable_id);
            if ($order) {
                // Only order participants (creator, assignee) + CHEF_CUISINE/CHEF_MAGASIN can see comments
                $allowedUserIds = array_filter([$order->created_by, $order->assigned_to]);
                if (! in_array($user->id, $allowedUserIds)) {
                    // Return empty - user is not a participant
                    return response()->json([]);
                }
                // Only show comments from participants
                $comments = $comments->whereIn('user_id', $allowedUserIds);
            }
        }

        // Role-based filtering for product comments
        if ($request->commentable_type === 'product' && ! in_array($role, ['SUPER_ADMIN', 'RESPONSABLE_ACHAT', 'CHEF_CUISINE', 'CHEF_MAGASIN'])) {
            $product = Product::find($request->commentable_id);
            if ($product && $product->created_by !== $user->id) {
                return response()->json([]);
            }
        }

        return response()->json($comments->get());
    }

    public function update(Request $request, Comment $comment): JsonResponse
    {
        if ($comment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Vous ne pouvez modifier que vos propres commentaires.'], 403);
        }

        $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $comment->update(['body' => $request->body]);

        return response()->json([
            'message' => 'Commentaire modifié.',
            'comment' => $comment->fresh()->load('user'),
        ]);
    }

    public function destroy(Comment $comment): JsonResponse
    {
        if ($comment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Vous ne pouvez supprimer que vos propres commentaires.'], 403);
        }

        $comment->delete();

        return response()->json(['message' => 'Commentaire supprimé.']);
    }
}
