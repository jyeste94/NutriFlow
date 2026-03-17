<?php

namespace App\Factory;

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Factory;

final class FirebaseAuthFactory
{
    public static function create(string $credentialsPath, string $projectId): Auth
    {
        return (new Factory())
            ->withServiceAccount($credentialsPath)
            ->withProjectId($projectId)
            ->createAuth();
    }
}

