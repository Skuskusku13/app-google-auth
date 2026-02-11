<?php

namespace App\Controller;

use App\Service\GoogleDocsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/docs')]
class DocsController extends AbstractController
{
    /**
     * Crée un nouveau document Google Docs avec du contenu formaté
     */
    #[Route('/create', name: 'docs_create', methods: ['GET', 'POST'])]
    public function create(Request $request, GoogleDocsService $docsService): Response
    {
        if (!$this->getUser()) {
            $this->addFlash('warning', 'Vous devez être connecté pour créer un document.');
            return $this->redirectToRoute('connect_google');
        }

        try {
            $title = trim((string) $request->request->get('title', ''));
            if ($title === '') {
                $title = 'Mon Document depuis Symfony';
            }

            // Créer le document
            $result = $docsService->createDocument($title);
            $documentId = $result['documentId'];

            // Ajouter le contenu
            $content = "Titre Principal\n\n";
            $content .= "Ceci est le premier paragraphe de mon document créé automatiquement.\n\n";
            $content .= "Voici un second paragraphe avec plus d'informations.\n\n";
            $content .= "Conclusion : C'est génial !";

            $docsService->addTextToDocument($documentId, $content);

            // Formater le titre (index 1 à 17 = "Titre Principal\n")
            $docsService->formatText(
                $documentId,
                startIndex: 1,
                endIndex: 17,
                bold: true,
                fontSize: 20,
                foregroundColor: '#4285f4'
            );

            $this->addFlash('success', 'Document créé avec succès !');

            return $this->render('docs/created.html.twig', [
                'documentId' => $result['documentId'],
                'title' => $result['title'],
                'url' => $result['url']
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la création du document : ' . $e->getMessage());
            return $this->redirectToRoute('connect_google');
        }
    }

    /**
     * Affiche les informations et le contenu d'un document
     */
    #[Route('/view/{documentId}', name: 'docs_view', methods: ['GET'])]
    public function view(string $documentId, GoogleDocsService $docsService): Response
    {
        if (!$this->getUser()) {
            $this->addFlash('warning', 'Vous devez être connecté pour voir ce document.');
            return $this->redirectToRoute('connect_google');
        }

        try {
            $info = $docsService->getDocumentInfo($documentId);
            $content = $docsService->getDocumentContent($documentId);

            return $this->render('docs/view.html.twig', [
                'info' => $info,
                'content' => $content
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Impossible de récupérer le document : ' . $e->getMessage());
            return $this->redirectToRoute('app_home');
        }
    }

    /**
     * Modifie le contenu d'un document existant
     */
    #[Route('/edit/{documentId}', name: 'docs_edit', methods: ['POST'])]
    public function edit(string $documentId, GoogleDocsService $docsService): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('connect_google');
        }

        try {
            $newContent = "Document mis à jour le " . date('d/m/Y à H:i') . "\n\n";
            $newContent .= "Ceci est le nouveau contenu du document.";

            $docsService->replaceDocumentContent($documentId, $newContent);

            $this->addFlash('success', 'Document mis à jour avec succès !');

            return $this->redirectToRoute('docs_view', ['documentId' => $documentId]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la mise à jour : ' . $e->getMessage());
            return $this->redirectToRoute('app_home');
        }
    }
}
