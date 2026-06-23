<?php
/**
 * Bootstrap - shared startup for every request.
 *  - loads config
 *  - registers a tiny class autoloader for helpers/models/controllers
 *  - starts the secure session
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

spl_autoload_register(function (string $class): void {
    foreach (['helpers', 'models', 'controllers'] as $dir) {
        $file = APP_PATH . '/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

Session::start();
