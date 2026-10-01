<?php

/**
 * PHPUnit bootstrap.
 *
 * The suite runs without a Craft application: every test exercises plugin logic
 * directly, which keeps it fast and keeps the tests honest about what each class
 * actually depends on.
 *
 * Craft's global `Craft` class is not autoloadable — Craft's own bootstrap requires
 * the file by hand — so code that only *logs* through it would be untestable. A
 * minimal stand-in fills that gap, and only when the real class is absent, so
 * running under Craft's test framework uses the real one.
 *
 * It deliberately provides nothing but the static log methods. Anything reaching for
 * `Craft::$app` still fails loudly, which is the point: a class that needs the
 * application does not belong in this suite.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists('Craft', false) && !class_exists('Yii', false)) {
    class Craft
    {
        /**
         * @var array<int, array{level: string, message: string, category: string}>
         */
        public static array $log = [];

        public static function info(string $message, string $category = 'application'): void
        {
            self::$log[] = ['level' => 'info', 'message' => $message, 'category' => $category];
        }

        public static function warning(string $message, string $category = 'application'): void
        {
            self::$log[] = ['level' => 'warning', 'message' => $message, 'category' => $category];
        }

        public static function error(string $message, string $category = 'application'): void
        {
            self::$log[] = ['level' => 'error', 'message' => $message, 'category' => $category];
        }
    }
}
