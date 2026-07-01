<?php

use App\Console\Commands\CheckIngredientStock;
use App\Models\Menu;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FIX 1: Changed from everyMinute() to hourly() — stock checks do not need per-minute frequency
Artisan::command('stock:check-ingredients', function () {
    $this->call(CheckIngredientStock::class);
})->purpose('Check FOOD product ingredient stock and auto-toggle is_active')->hourly();

// FIX 3: Thursday 20:00 planning reminder — notify Chef Cuisine if next week menu is missing
Artisan::command('menu:planning-reminder', function () {
    $nextWeekStart = now()->addWeek()->startOfWeek()->toDateString();
    $nextWeekEnd = now()->addWeek()->endOfWeek()->toDateString();

    $nextWeekMenu = Menu::where('start_date', $nextWeekStart)
        ->where('end_date', $nextWeekEnd)
        ->first();

    if (! $nextWeekMenu) {
        $chefCuisineUsers = User::whereHas('role', fn ($q) => $q->where('name', 'CHEF_CUISINE'))->get();

        foreach ($chefCuisineUsers as $chef) {
            Notification::create([
                'user_id' => $chef->id,
                'title' => ' Rappel : Menu semaine prochaine non planifié',
                'message' => "Il est 20h00 (jeudi). Le menu de la semaine du {$nextWeekStart} au {$nextWeekEnd} n'a pas encore été créé. Veuillez planifier le menu dès que possible.",
                'type' => 'warning',
                'is_read' => false,
                'data' => ['next_week_start' => $nextWeekStart, 'next_week_end' => $nextWeekEnd],
            ]);
        }

        $this->info('Planning reminder sent to '.$chefCuisineUsers->count().' Chef(s) Cuisine.');
    } else {
        $this->info("Next week menu already exists: {$nextWeekMenu->name}. No reminder sent.");
    }
})->purpose('Remind Chef Cuisine to plan next week menu every Thursday at 20:00')->weeklyOn(4, '20:00');
