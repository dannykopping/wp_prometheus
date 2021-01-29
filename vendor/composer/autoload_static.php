<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit25b38bab638fb2b429e822fff437cfc6
{
    public static $files = array (
        '32dcc8afd4335739640db7d200c1971d' => __DIR__ . '/..' . '/symfony/polyfill-apcu/bootstrap.php',
    );

    public static $prefixLengthsPsr4 = array (
        'S' => 
        array (
            'Symfony\\Polyfill\\Apcu\\' => 22,
        ),
        'P' => 
        array (
            'Prometheus\\' => 11,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Symfony\\Polyfill\\Apcu\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/polyfill-apcu',
        ),
        'Prometheus\\' => 
        array (
            0 => __DIR__ . '/..' . '/promphp/prometheus_client_php/src/Prometheus',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit25b38bab638fb2b429e822fff437cfc6::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit25b38bab638fb2b429e822fff437cfc6::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
