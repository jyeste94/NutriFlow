<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
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

class TestHeaderAuthenticator extends AbstractAuthenticator
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/v1');
    }

    public function authenticate(Request $request): Passport
    {
        $uid = trim((string) $request->headers->get('X-Test-User', ''));
        if ($uid === '') {
            throw new CustomUserMessageAuthenticationException('Missing X-Test-User header');
        }

        $email = trim((string) $request->headers->get('X-Test-Email', ''));
        $normalizedEmail = $email !== '' ? mb_strtolower($email) : null;

        return new SelfValidatingPassport(
            new UserBadge($uid, fn (string $userIdentifier) => $this->resolveUser($userIdentifier, $normalizedEmail))
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => strtr($exception->getMessageKey(), $exception->getMessageData())],
            Response::HTTP_UNAUTHORIZED
        );
    }

    private function resolveUser(string $firebaseUid, ?string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['firebaseUid' => $firebaseUid]);

        if (!$user) {
            $user = new User();
            $user->setFirebaseUid($firebaseUid);
            $user->setEmail($email);
            $this->em->persist($user);
            $this->em->flush();
            return $user;
        }

        if ($email !== null && $user->getEmail() !== $email) {
            $user->setEmail($email);
            $this->em->flush();
        }

        return $user;
    }
}
