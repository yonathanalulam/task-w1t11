<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthSessionService
{
    public const SESSION_USER_ID_KEY = 'auth_user_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UserRepository $users,
    ) {
    }

    public function currentUser(): ?User
    {
        $session = $this->requestStack->getSession();
        if (!$session) {
            return null;
        }

        $id = $session->get(self::SESSION_USER_ID_KEY);
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }

        return $this->users->find((int) $id);
    }

    public function login(User $user): void
    {
        $session = $this->requestStack->getSession();
        if (!$session) {
            throw new \RuntimeException('Session is unavailable for login.');
        }

        $session->migrate(true);
        $session->set(self::SESSION_USER_ID_KEY, $user->getId());
    }

    public function logout(): void
    {
        $session = $this->requestStack->getSession();
        if ($session) {
            $session->invalidate();
        }
    }
}
