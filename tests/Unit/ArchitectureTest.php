<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ports & adapters boundaries
|--------------------------------------------------------------------------
|
| Enforced mechanically rather than by discipline. Hexagonal architecture
| erodes one convenient `use Illuminate\...` at a time, and by the time anyone
| notices, the domain is untestable without booting the framework.
|
*/

arch('the domain is framework-agnostic')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate', 'App\Infrastructure', 'App\Application', 'App\Models']);

arch('the application layer talks to the world only through ports')
    ->expect('App\Application')
    ->not->toUse(['Illuminate', 'App\Infrastructure', 'App\Models']);

arch('dependencies point inward, never outward')
    ->expect('App\Infrastructure')
    ->not->toUse('App\Http');

arch('domain code declares strict types')
    ->expect('App\Domain')
    ->toUseStrictTypes();

arch('application code declares strict types')
    ->expect('App\Application')
    ->toUseStrictTypes();

arch('nothing is left debugging')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();
