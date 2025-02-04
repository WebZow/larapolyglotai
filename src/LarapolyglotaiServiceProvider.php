<?php

namespace WebZOW\Larapolyglotai;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use WebZOW\Larapolyglotai\Commands\LarapolyglotaiCommand;

class LarapolyglotaiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('larapolyglotai')
            ->hasConfigFile()
            ->hasCommand(LarapolyglotaiCommand::class);
    }
}
