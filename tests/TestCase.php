<?php

namespace Tests;

use CondoHub\Reservas\Application\Port\VerificadorToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Tokens;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Los tests verifican tokens con una clave propia en vez de pedir el JWKS a Keycloak.
        $this->app->instance(VerificadorToken::class, Tokens::verificador());
    }

    /** @param  list<string>  $roles */
    protected function conRol(array $roles): static
    {
        return $this->withToken(Tokens::crear($roles));
    }
}
