<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\Auth\Token\Exception\InvalidToken;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\InvalidArgumentException as FirebaseInvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class FirebaseAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private Auth $firebaseAuth,
        private EntityManagerInterface $em
    ) {}

    public function supports(Request $request): ?bool
    {
        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'test') {
            return false;
        }

        if ($request->isMethod('OPTIONS')) {
            return false;
        }

        return str_starts_with($request->getPathInfo(), '/v1');
    }

    public function authenticate(Request $request): Passport
    {
        $authorization = $request->headers->get('Authorization');
        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException('No API token provided');
        }

        $idTokenString = trim(substr($authorization, 7));
        if ($idTokenString === '') {
            throw new CustomUserMessageAuthenticationException('No API token provided');
        }

        try {
            $verifiedIdToken = $this->firebaseAuth->verifyIdToken($idTokenString);
            $uid = (string) $verifiedIdToken->claims()->get('sub');
            $email = $verifiedIdToken->claims()->get('email');
        } catch (FailedToVerifyToken|InvalidToken|FirebaseInvalidArgumentException) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired Firebase token');
        } catch (\Throwable $exception) {
            $isDev = (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'dev');
            if ($isDev) {
                error_log(sprintf(
                    '[FirebaseAuthenticator] Unexpected auth verification error: %s - %s',
                    $exception::class,
                    $exception->getMessage()
                ));
            }

            throw new CustomUserMessageAuthenticationException('Authentication service unavailable');
        }

        return new SelfValidatingPassport(
            new UserBadge($uid, fn (string $userIdentifier) => $this->resolveUser($userIdentifier, $email))
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $data = [
            'error' => strtr($exception->getMessageKey(), $exception->getMessageData()),
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }

    private function resolveUser(string $userIdentifier, mixed $emailClaim): User
    {
        $connection = $this->em->getConnection();
        $lockName = 'nutriflow_firebase_user_' . substr(hash('sha256', $userIdentifier), 0, 32);

        try {
            $acquired = (int) $connection->fetchOne(
                'SELECT GET_LOCK(:lock_name, 5)',
                ['lock_name' => $lockName]
            );
        } catch (\Throwable) {
            throw new CustomUserMessageAuthenticationException('Authentication service unavailable');
        }

        if ($acquired !== 1) {
            throw new CustomUserMessageAuthenticationException('Authentication service busy, retry');
        }

        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['firebaseUid' => $userIdentifier]);
            $email = is_string($emailClaim) && $emailClaim !== '' ? mb_strtolower($emailClaim) : null;

            if (!$user) {
                $user = new User();
                $user->setFirebaseUid($userIdentifier);
                $user->setEmail($email);
                $this->em->persist($user);
                $this->em->flush();
            } elseif ($email !== null && $user->getEmail() !== $email) {
                $user->setEmail($email);
                $this->em->flush();
            }

            return $user;
        } finally {
            try {
                $connection->fetchOne('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lockName]);
            } catch (\Throwable) {
                // Ignore lock release errors: lock auto-releases when connection closes
            }
        }
    }
}
