<?php

namespace Tests\Feature;

use CarlVallory\KrayinNetValue\Providers\KrayinNetValueServiceProvider;
use Tests\TestCase;

/**
 * El gate de la doc Swagger en producción lo aplica el paquete KrayinNetValue
 * (config()->set en register()), NO el config del fork: producción corre el
 * core upstream de Krayin sin fork y todo lo propio entra vía paquetes.
 *
 * El middleware debe ser ['web', 'user'] (Bouncer de Krayin): sin sesión
 * redirige a admin.session.create. Con 'auth:user' un anónimo recibía un
 * 500 "Route [login] not defined" porque Krayin no nombra la ruta 'login'.
 */
class SwaggerDocsGateTest extends TestCase
{
    public function test_en_produccion_el_paquete_protege_api_y_docs_con_web_y_bouncer(): void
    {
        $this->app['env'] = 'production';

        (new KrayinNetValueServiceProvider($this->app))->register();

        $this->assertSame(['web', 'user'], config('l5-swagger.defaults.routes.middleware.api'));
        $this->assertSame(['web', 'user'], config('l5-swagger.defaults.routes.middleware.docs'));
    }

    public function test_fuera_de_produccion_la_doc_queda_abierta(): void
    {
        // El env de la suite es 'testing': el register() no debe tocar nada.
        (new KrayinNetValueServiceProvider($this->app))->register();

        $this->assertSame([], config('l5-swagger.defaults.routes.middleware.api'));
        $this->assertSame([], config('l5-swagger.defaults.routes.middleware.docs'));
    }
}
