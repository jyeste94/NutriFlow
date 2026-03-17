<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

$projectDir = dirname(__DIR__);
$localFirebaseCredentials = $projectDir.'/config/firebase_credentials.local.json';
$exampleFirebaseCredentials = $projectDir.'/config/firebase_credentials.example.json';

if (is_file($exampleFirebaseCredentials)) {
    $rawExample = file_get_contents($exampleFirebaseCredentials);
    if ($rawExample !== false) {
        $normalizedExample = ltrim($rawExample, "\xEF\xBB\xBF");
        $decodedExample = json_decode($normalizedExample, true);

        if (is_array($decodedExample)) {
            $encodedExample = json_encode($decodedExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encodedExample !== false) {
                if (!is_file($localFirebaseCredentials)) {
                    file_put_contents($localFirebaseCredentials, $encodedExample);
                } else {
                    $rawLocal = file_get_contents($localFirebaseCredentials);
                    $hasBom = is_string($rawLocal) && str_starts_with($rawLocal, "\xEF\xBB\xBF");
                    $decodedLocal = is_string($rawLocal) ? json_decode(ltrim($rawLocal, "\xEF\xBB\xBF"), true) : null;
                    if (!is_array($decodedLocal)) {
                        file_put_contents($localFirebaseCredentials, $encodedExample);
                    } elseif ($hasBom) {
                        $encodedLocal = json_encode($decodedLocal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        if ($encodedLocal !== false) {
                            file_put_contents($localFirebaseCredentials, $encodedLocal);
                        }
                    }
                }
            }
        }
    }
}

$_SERVER['FIREBASE_CREDENTIALS_PATH'] = $localFirebaseCredentials;
$_ENV['FIREBASE_CREDENTIALS_PATH'] = $localFirebaseCredentials;

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
