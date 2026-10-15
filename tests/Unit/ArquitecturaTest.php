<?php

// Clean: Domain y Application no conocen Laravel. Las dependencias apuntan hacia el dominio.

arch('el dominio no usa Laravel ni librerías de infraestructura')
    ->expect('CondoHub\Reservas\Domain')
    ->not->toUse(['Illuminate', 'Firebase', 'App']);

arch('la aplicación no usa Laravel ni librerías de infraestructura')
    ->expect('CondoHub\Reservas\Application')
    ->not->toUse(['Illuminate', 'Firebase', 'App', 'CondoHub\Reservas\Infrastructure']);

arch('el dominio no depende de la aplicación ni de la infraestructura')
    ->expect('CondoHub\Reservas\Domain')
    ->not->toUse(['CondoHub\Reservas\Application', 'CondoHub\Reservas\Infrastructure']);

arch('no se usa dd, dump ni var_dump')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();
