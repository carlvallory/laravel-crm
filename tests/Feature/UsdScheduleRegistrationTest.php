<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * El scheduling del BCP/USD debe registrarlo el paquete KrayinNetValue (no el
 * Kernel de la app): producción corre el core upstream de Krayin sin fork, y
 * todo lo propio llega únicamente vía paquetes composer.
 */
class UsdScheduleRegistrationTest extends TestCase
{
    private function eventsFor(string $command): array
    {
        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($event) => str_contains((string) $event->command, $command)
        ));
    }

    public function test_exchange_rates_poll_corre_tres_veces_por_tarde_en_dias_habiles(): void
    {
        $expressions = array_map(
            fn ($event) => $event->expression,
            $this->eventsFor('exchange-rates:poll')
        );

        sort($expressions);

        $this->assertSame(
            ['0 14 * * 1-5', '0 16 * * 1-5', '0 18 * * 1-5'],
            $expressions
        );
    }

    public function test_leads_backfill_usd_corre_cada_noche_para_el_anio_en_curso(): void
    {
        $events = $this->eventsFor('leads:backfill-usd');

        $this->assertCount(1, $events);
        $this->assertSame('0 2 * * *', $events[0]->expression);
        $this->assertStringContainsString('leads:backfill-usd ' . date('Y'), (string) $events[0]->command);
    }
}
