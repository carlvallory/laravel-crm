<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('inbound-emails:process')->everyFiveMinutes();

        // Poll de la cotización BCP: solo días hábiles, a la tarde (la referencial
        // cierra después de las 13:00). Tres corridas como reintento por resiliencia.
        $schedule->command('exchange-rates:poll')->weekdays()->at('14:00');
        $schedule->command('exchange-rates:poll')->weekdays()->at('16:00');
        $schedule->command('exchange-rates:poll')->weekdays()->at('18:00');

        // Conversión USD de los leads del año en curso, todas las noches.
        $schedule->command('leads:backfill-usd ' . date('Y'))->dailyAt('02:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
