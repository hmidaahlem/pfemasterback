<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
// Définir QUI peut écouter le channel

Broadcast::channel('notifications.{userId}', function (User $user, int $userId) {
    return (int) $user->id === $userId;
});

Broadcast::channel('orders', function (User $user) {
    return true;
});

Broadcast::channel('hygiene', function (User $user) {
    return in_array($user->role?->name, ['SUPER_ADMIN', 'CHEF_MAGASIN', 'CHEF_CUISINE', 'RESPONSABLE_ACHAT', 'RESPONSABLE_HYGIENE']);
});
