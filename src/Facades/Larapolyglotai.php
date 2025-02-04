<?php

namespace WebZOW\Larapolyglotai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \WebZOW\Larapolyglotai\Larapolyglotai
 */
class Larapolyglotai extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \WebZOW\Larapolyglotai\Larapolyglotai::class;
    }
}
