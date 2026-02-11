<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Google\Service\Docs;
use Google\Service\Docs\BatchUpdateDocumentRequest;
use Google\Service\Docs\DeleteContentRangeRequest;
use Google\Service\Docs\Dimension;
use Google\Service\Docs\InsertTextRequest;
use Google\Service\Docs\Location;
use Google\Service\Docs\Range;
use Google\Service\Docs\Request as DocsRequest;
use Google\Service\Docs\TextStyle;
use Google\Service\Docs\UpdateTextStyleRequest;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class GoogleDocsService
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')]
        private string $googleClientId,
        #[Autowire('%env(GOOGLE_CLIENT_SECRET)%')]
        private string $googleClientSecret
    ) {}

    /**
     * Initialise et retourne un client Google API authentifié
     *
     * @throws \Exception Si l'utilisateur n'est pas authentifié
     */
    private function getClient(): Client
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \Exception('Non authentifié avec Google. Veuillez vous connecter.');
        }

        $accessToken = $user->getGoogleAccessToken();
        if (!$accessToken) {
            throw new \Exception('Token Google introuvable. Veuillez reconnecter votre compte Google.');
        }

        $client = new Client();
        $client->setClientId($this->googleClientId);
        $client->setClientSecret($this->googleClientSecret);
        $client->setScopes([
            'https://www.googleapis.com/auth/documents',
            'https://www.googleapis.com/auth/drive.file',
        ]);
        $client->setAccessToken([
            'access_token' => $accessToken,
            'refresh_token' => $user->getGoogleRefreshToken(),
            'expires_at' => $user->getGoogleTokenExpiresAt()?->getTimestamp(),
        ]);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $user->getGoogleRefreshToken();
            if (!$refreshToken) {
                throw new \Exception('Session Google expirée et refresh token absent. Veuillez vous reconnecter.');
            }

            $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            if (isset($newToken['error'])) {
                $description = isset($newToken['error_description']) ? sprintf(' (%s)', $newToken['error_description']) : '';
                throw new \Exception(sprintf('Impossible de rafraîchir le token Google: %s%s', $newToken['error'], $description));
            }

            if (!isset($newToken['access_token']) || !is_string($newToken['access_token'])) {
                throw new \Exception('Google n\'a pas renvoyé de nouvel access token.');
            }

            $user->setGoogleAccessToken($newToken['access_token']);
            if (isset($newToken['refresh_token']) && is_string($newToken['refresh_token']) && $newToken['refresh_token'] !== '') {
                $user->setGoogleRefreshToken($newToken['refresh_token']);
            }
            if (isset($newToken['expires_in']) && is_numeric($newToken['expires_in'])) {
                $user->setGoogleTokenExpiresAt((new \DateTimeImmutable())->modify('+' . (int) $newToken['expires_in'] . ' seconds'));
            }
            $this->entityManager->flush();

            $client->setAccessToken([
                'access_token' => $user->getGoogleAccessToken(),
                'refresh_token' => $user->getGoogleRefreshToken(),
                'expires_at' => $user->getGoogleTokenExpiresAt()?->getTimestamp(),
            ]);
        }

        return $client;
    }

    /**
     * Crée un nouveau document Google Docs
     *
     * @param string $title Le titre du document
     * @return array ['documentId' => string, 'title' => string, 'url' => string]
     * @throws \Exception
     */
    public function createDocument(string $title): array
    {
        $client = $this->getClient();
        $service = new Docs($client);

        $document = new Docs\Document(['title' => $title]);
        $doc = $service->documents->create($document);

        return [
            'documentId' => $doc->getDocumentId(),
            'title' => $doc->getTitle(),
            'url' => "https://docs.google.com/document/d/{$doc->getDocumentId()}/edit"
        ];
    }

    /**
     * Récupère un document complet par son ID
     *
     * @param string $documentId L'ID du document
     * @param string|null $suggestionsViewMode Mode d'affichage des suggestions (DEFAULT_FOR_CURRENT_ACCESS, SUGGESTIONS_INLINE, PREVIEW_SUGGESTIONS_ACCEPTED, PREVIEW_WITHOUT_SUGGESTIONS)
     * @param bool $includeTabsContent Si true, renseigne Document.tabs au lieu des champs de contenu textuel
     * @return Docs\Document
     * @throws \Exception
     */
    public function getDocument(
        string $documentId,
        ?string $suggestionsViewMode = null,
        bool $includeTabsContent = false
    ): Docs\Document {
        $client = $this->getClient();
        $service = new Docs($client);

        $optParams = [];

        if ($suggestionsViewMode) {
            $optParams['suggestionsViewMode'] = $suggestionsViewMode;
        }

        if ($includeTabsContent) {
            $optParams['includeTabsContent'] = true;
        }

        return $service->documents->get($documentId, $optParams);
    }

    /**
     * Extrait uniquement le contenu texte d'un document
     *
     * @param string $documentId L'ID du document
     * @return string Le contenu textuel complet
     * @throws \Exception
     */
    public function getDocumentContent(string $documentId): string
    {
        $doc = $this->getDocument($documentId);
        $content = '';

        if (!$doc->getBody() || !$doc->getBody()->getContent()) {
            return $content;
        }

        foreach ($doc->getBody()->getContent() as $element) {
            if ($element->getParagraph()) {
                foreach ($element->getParagraph()->getElements() as $paragraphElement) {
                    if ($paragraphElement->getTextRun()) {
                        $content .= $paragraphElement->getTextRun()->getContent();
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Récupère les métadonnées du document (titre, ID, révision)
     *
     * @param string $documentId L'ID du document
     * @return array ['documentId' => string, 'title' => string, 'revisionId' => string, 'url' => string]
     * @throws \Exception
     */
    public function getDocumentInfo(string $documentId): array
    {
        $doc = $this->getDocument($documentId);

        return [
            'documentId' => $doc->getDocumentId(),
            'title' => $doc->getTitle(),
            'revisionId' => $doc->getRevisionId(),
            'url' => "https://docs.google.com/document/d/{$doc->getDocumentId()}/edit"
        ];
    }

    /**
     * Insère du texte à une position spécifique dans le document
     *
     * @param string $documentId L'ID du document
     * @param string $text Le texte à insérer
     * @param int $index Position d'insertion (1 = début du document)
     * @throws \Exception
     */
    public function addTextToDocument(string $documentId, string $text, int $index = 1): void
    {
        $client = $this->getClient();
        $service = new Docs($client);

        $requests = [
            new DocsRequest([
                'insertText' => new InsertTextRequest([
                    'location' => new Location(['index' => $index]),
                    'text' => $text
                ])
            ])
        ];

        $batchUpdateRequest = new BatchUpdateDocumentRequest(['requests' => $requests]);
        $service->documents->batchUpdate($documentId, $batchUpdateRequest);
    }

    /**
     * Remplace tout le contenu du document par un nouveau texte
     *
     * @param string $documentId L'ID du document
     * @param string $newContent Le nouveau contenu
     * @throws \Exception
     */
    public function replaceDocumentContent(string $documentId, string $newContent): void
    {
        $client = $this->getClient();
        $service = new Docs($client);

        // Récupérer l'index de fin du document
        $doc = $this->getDocument($documentId);
        $bodyContent = $doc->getBody()->getContent();
        $endIndex = end($bodyContent)->getEndIndex() - 1;

        $requests = [
            // 1. Supprimer tout le contenu existant
            new DocsRequest([
                'deleteContentRange' => new DeleteContentRangeRequest([
                    'range' => new Range([
                        'startIndex' => 1,
                        'endIndex' => $endIndex
                    ])
                ])
            ]),
            // 2. Insérer le nouveau contenu
            new DocsRequest([
                'insertText' => new InsertTextRequest([
                    'location' => new Location(['index' => 1]),
                    'text' => $newContent
                ])
            ])
        ];

        $batchUpdateRequest = new BatchUpdateDocumentRequest(['requests' => $requests]);
        $service->documents->batchUpdate($documentId, $batchUpdateRequest);
    }

    /**
     * Applique un style de texte sur une plage spécifique
     *
     * @param string $documentId L'ID du document
     * @param int $startIndex Index de début (inclusif)
     * @param int $endIndex Index de fin (exclusif)
     * @param bool|null $bold Mettre en gras
     * @param bool|null $italic Mettre en italique
     * @param int|null $fontSize Taille de police en points
     * @param string|null $foregroundColor Couleur du texte (format: "#RRGGBB")
     * @throws \Exception
     */
    public function formatText(
        string $documentId,
        int $startIndex,
        int $endIndex,
        ?bool $bold = null,
        ?bool $italic = null,
        ?int $fontSize = null,
        ?string $foregroundColor = null
    ): void {
        $client = $this->getClient();
        $service = new Docs($client);

        $textStyle = new TextStyle();
        $fields = [];

        if ($bold !== null) {
            $textStyle->setBold($bold);
            $fields[] = 'bold';
        }

        if ($italic !== null) {
            $textStyle->setItalic($italic);
            $fields[] = 'italic';
        }

        if ($fontSize !== null) {
            $textStyle->setFontSize(new Dimension([
                'magnitude' => $fontSize,
                'unit' => 'PT'
            ]));
            $fields[] = 'fontSize';
        }

        if ($foregroundColor !== null) {
            $textStyle->setForegroundColor(new Docs\OptionalColor([
                'color' => new Docs\Color([
                    'rgbColor' => $this->hexToRgb($foregroundColor)
                ])
            ]));
            $fields[] = 'foregroundColor';
        }

        $requests = [
            new DocsRequest([
                'updateTextStyle' => new UpdateTextStyleRequest([
                    'range' => new Range([
                        'startIndex' => $startIndex,
                        'endIndex' => $endIndex
                    ]),
                    'textStyle' => $textStyle,
                    'fields' => implode(',', $fields)
                ])
            ])
        ];

        $batchUpdateRequest = new BatchUpdateDocumentRequest(['requests' => $requests]);
        $service->documents->batchUpdate($documentId, $batchUpdateRequest);
    }

    /**
     * Convertit une couleur hexadécimale en objet RgbColor
     *
     * @param string $hex Format: "#RRGGBB"
     * @return Docs\RgbColor
     */
    private function hexToRgb(string $hex): Docs\RgbColor
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        return new Docs\RgbColor([
            'red' => $r,
            'green' => $g,
            'blue' => $b
        ]);
    }
}
