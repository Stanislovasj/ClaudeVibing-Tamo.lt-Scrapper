<?php

final class Config
{
    private static ?array $config = null;

    public static function get(): array
    {
        if (self::$config === null) {
            $path = __DIR__ . '/../config.php';
            if (!is_file($path)) {
                throw new RuntimeException(
                    "Trūksta config.php. Nukopijuokite config.example.php į config.php ir įrašykite savo duomenis."
                );
            }
            self::$config = require $path;
        }
        return self::$config;
    }

    /** @return array<int, array{id:string, vardas:string, username:string, password:string}> */
    public static function mokiniai(): array
    {
        return self::get()['mokiniai'] ?? [];
    }

    public static function mokinys(string $id): ?array
    {
        foreach (self::mokiniai() as $m) {
            if ($m['id'] === $id) {
                return $m;
            }
        }
        return null;
    }
}
