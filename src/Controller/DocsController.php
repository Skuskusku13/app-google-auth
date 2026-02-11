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
     * Crée un nouveau document Google Docs avec du contenu riche
     */
    #[Route('/create', name: 'docs_create', methods: ['POST'])]
    public function create(Request $request, GoogleDocsService $docsService): Response
    {
        if (!$this->getUser()) {
            $this->addFlash('warning', 'Vous devez être connecté pour créer un document.');
            return $this->redirectToRoute('connect_google');
        }

        try {
            $title = trim((string) $request->request->get('title', ''));
            if ($title === '') {
                $title = 'Document sans titre';
            }

            $contentHtml = (string) $request->request->get('content_html', '');
            $contentDelta = (string) $request->request->get('content_delta', '');
            if (trim(strip_tags($contentHtml)) === '') {
                $this->addFlash('error', 'Le contenu du document est obligatoire.');
                return $this->redirectToRoute('app_dashboard');
            }

            // Créer le document à partir du Delta Quill (plus fiable pour les styles)
            $result = $docsService->createDocumentFromRichContent($title, $contentDelta, $contentHtml);
            $documentId = $result['documentId'];

            $this->addFlash('success', sprintf('Document "%s" créé avec succès.', $result['title']));
            $this->addFlash('doc_url', $result['url']);
            $this->addFlash('doc_view_url', $this->generateUrl('docs_view', ['documentId' => $documentId]));

            return $this->redirectToRoute('app_dashboard');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la création du document : ' . $e->getMessage());
            return $this->redirectToRoute('app_dashboard');
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
