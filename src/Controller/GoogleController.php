<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GoogleController extends AbstractController
{
    #[Route('/connect/google', name: 'connect_google')]
    public function connectAction(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry
            ->getClient('google')
            ->redirect([
                'email', 'profile'
            ], []);
    }

    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function connectCheckAction(Request $request): Response
    {
        // Cette route sera gérée par l'authenticator
        return new Response('Should not reach here');
    }

    #[Route('/connect/google/success', name: 'connect_google_success')]
    public function connectSuccessAction(): Response
    {
        return new Response(<<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Connexion réussie</title>
</head>
<body>
    <script>
        // Si la fenêtre a un "parent" (opener), on le redirige vers le dashboard et on ferme celle-ci
        if (window.opener) {
            window.opener.location.href = '/dashboard';
            window.close();
        } else {
            // Fallback si pas de popup (accès direct)
            window.location.href = '/dashboard';
        }
    </script>
    <p>Connexion réussie. Redirection...</p>
</body>
</html>
HTML
        );
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): void
    {
        // Symfony gère automatiquement la déconnexion
        throw new \LogicException('This method can be blank');
    }
}
