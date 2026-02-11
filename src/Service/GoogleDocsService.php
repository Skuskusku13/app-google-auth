<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Google\Service\Docs;
use Google\Service\Docs\BatchUpdateDocumentRequest;
use Google\Service\Docs\CreateParagraphBulletsRequest;
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
     * @return array{documentId: string, title: string, url: string}
     * @throws \Exception
     */
    public function createDocument(string $title): array
    {
        $client = $this->getClient();
        $service = new Docs($client);

        $document = new Docs\Document(['title' => $title]);
        $doc = $service->documents->create($document);

        $documentId = (string) $doc->getDocumentId();

        return [
            'documentId' => $documentId,
            'title' => (string) $doc->getTitle(),
            'url' => "https://docs.google.com/document/d/{$documentId}/edit",
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
        $bodyContent = $doc->getBody()->getContent();
        if ($bodyContent === []) {
            return '';
        }

        $content = '';
        foreach ($bodyContent as $element) {
            $paragraph = $element->getParagraph();
            /** @var Docs\Paragraph|null $paragraph */
            if ($paragraph === null) {
                continue;
            }

            $paragraphElements = $paragraph->getElements();
            /** @var array<Docs\ParagraphElement>|null $paragraphElements */
            if ($paragraphElements === null) {
                continue;
            }

            foreach ($paragraphElements as $paragraphElement) {
                $textRun = $paragraphElement->getTextRun();
                /** @var Docs\TextRun|null $textRun */
                if ($textRun !== null) {
                    $content .= (string) $textRun->getContent();
                }
            }
        }

        return $content;
    }

    /**
     * Récupère les métadonnées du document (titre, ID, révision)
     *
     * @param string $documentId L'ID du document
     * @return array{documentId: string, title: string, revisionId: string, url: string}
     * @throws \Exception
     */
    public function getDocumentInfo(string $documentId): array
    {
        $doc = $this->getDocument($documentId);
        $id = (string) $doc->getDocumentId();

        return [
            'documentId' => $id,
            'title' => (string) $doc->getTitle(),
            'revisionId' => (string) $doc->getRevisionId(),
            'url' => "https://docs.google.com/document/d/{$id}/edit",
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
        if ($bodyContent === []) {
            throw new \RuntimeException('Le document ne contient aucun élément éditable.');
        }

        $lastElement = end($bodyContent);
        $endIndex = $lastElement->getEndIndex() - 1;

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
     * Crée un nouveau document Google Docs à partir de contenu HTML
     *
     * @param string $title Le titre du document
     * @param string $html Le contenu HTML (supporte b, i, u, span style color, etc.)
     * @return array{documentId: string, title: string, url: string}
     * @throws \Exception
     */
    public function createDocumentFromHtml(string $title, string $html): array
    {
        $result = $this->createDocument($title);
        $documentId = $result['documentId'];

        $this->applyHtmlToDocument($documentId, $html);

        return $result;
    }

    /**
     * Crée un document depuis contenu riche Quill (Delta prioritaire, HTML en fallback)
     *
     * @return array{documentId: string, title: string, url: string}
     */
    public function createDocumentFromRichContent(string $title, ?string $deltaJson, string $htmlFallback): array
    {
        if (is_string($deltaJson) && trim($deltaJson) !== '') {
            try {
                return $this->createDocumentFromDelta($title, $deltaJson);
            } catch (\Throwable) {
                // Fallback robuste sur le parseur HTML existant
            }
        }

        return $this->createDocumentFromHtml($title, $htmlFallback);
    }

    /**
     * Crée un document depuis le Delta Quill pour préserver bold/italic/color/size/header.
     *
     * @return array{documentId: string, title: string, url: string}
     * @throws \JsonException
     */
    public function createDocumentFromDelta(string $title, string $deltaJson): array
    {
        $delta = json_decode($deltaJson, true, 512, JSON_THROW_ON_ERROR);
        $ops = $delta['ops'] ?? null;
        if (!is_array($ops)) {
            throw new \InvalidArgumentException('Delta Quill invalide.');
        }

        $result = $this->createDocument($title);
        $documentId = $result['documentId'];

        $fullText = '';
        $textRuns = [];
        $paragraphRuns = [];
        $listRuns = [];

        $currentIndex = 1;
        $paragraphStart = 1;

        foreach ($ops as $op) {
            $insert = $op['insert'] ?? null;
            if (!is_string($insert) || $insert === '') {
                continue;
            }

            $attrs = isset($op['attributes']) && is_array($op['attributes']) ? $op['attributes'] : [];
            $parts = preg_split('/(\n)/u', $insert, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false) {
                continue;
            }

            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                if ($part === "\n") {
                    $fullText .= "\n";
                    $lineEnd = $currentIndex + 1;
                    $paragraphRuns[] = [
                        'start' => $paragraphStart,
                        'end' => $lineEnd,
                        'attrs' => $attrs,
                    ];
                    $bulletPreset = $this->quillListToBulletPreset($attrs['list'] ?? null);
                    if ($bulletPreset !== null) {
                        $listRuns[] = [
                            'start' => $paragraphStart,
                            'end' => $lineEnd,
                            'bulletPreset' => $bulletPreset,
                        ];
                    }
                    $currentIndex = $lineEnd;
                    $paragraphStart = $currentIndex;
                    continue;
                }

                $length = $this->utf16Length($part);
                if ($length <= 0) {
                    continue;
                }

                $start = $currentIndex;
                $end = $currentIndex + $length;
                $fullText .= $part;

                if (!empty($attrs)) {
                    $textRuns[] = [
                        'start' => $start,
                        'end' => $end,
                        'attrs' => $attrs,
                    ];
                }

                $currentIndex = $end;
            }
        }

        if ($fullText === '') {
            throw new \InvalidArgumentException('Le contenu du document est vide.');
        }

        if (!str_ends_with($fullText, "\n")) {
            $fullText .= "\n";
            $paragraphRuns[] = [
                'start' => $paragraphStart,
                'end' => $currentIndex + 1,
                'attrs' => [],
            ];
        }

        $client = $this->getClient();
        $service = new Docs($client);
        $requests = [];

        $requests[] = new DocsRequest([
            'insertText' => new InsertTextRequest([
                'location' => new Location(['index' => 1]),
                'text' => $fullText,
            ]),
        ]);

        foreach ($paragraphRuns as $run) {
            $attrs = $run['attrs'];
            $paragraphStyle = new Docs\ParagraphStyle();
            $fields = [];

            $namedStyleType = $this->quillHeaderToNamedStyleType($attrs['header'] ?? null);
            if ($namedStyleType !== null) {
                $paragraphStyle->setNamedStyleType($namedStyleType);
                $fields[] = 'namedStyleType';
            }

            $alignment = $this->quillAlignToAlignment($attrs['align'] ?? null);
            if ($alignment !== null) {
                $paragraphStyle->setAlignment($alignment);
                $fields[] = 'alignment';
            }

            if (!empty($fields)) {
                $requests[] = new DocsRequest([
                    'updateParagraphStyle' => new Docs\UpdateParagraphStyleRequest([
                        'range' => new Range([
                            'startIndex' => $run['start'],
                            'endIndex' => $run['end'],
                        ]),
                        'paragraphStyle' => $paragraphStyle,
                        'fields' => implode(',', $fields),
                    ]),
                ]);
            }
        }

        foreach ($listRuns as $run) {
            $requests[] = new DocsRequest([
                'createParagraphBullets' => new CreateParagraphBulletsRequest([
                    'range' => new Range([
                        'startIndex' => $run['start'],
                        'endIndex' => $run['end'],
                    ]),
                    'bulletPreset' => $run['bulletPreset'],
                ]),
            ]);
        }

        // Appliquer les styles texte en dernier pour éviter qu'un style de paragraphe (heading)
        // n'écrase la couleur/format du texte.
        foreach ($textRuns as $run) {
            $textStyle = new TextStyle();
            $fields = [];
            $attrs = $run['attrs'];

            if (!empty($attrs['bold'])) {
                $textStyle->setBold(true);
                $fields[] = 'bold';
            }
            if (!empty($attrs['italic'])) {
                $textStyle->setItalic(true);
                $fields[] = 'italic';
            }
            if (!empty($attrs['underline'])) {
                $textStyle->setUnderline(true);
                $fields[] = 'underline';
            }

            $fontSize = $this->quillSizeToPt($attrs['size'] ?? null);
            if ($fontSize !== null) {
                $textStyle->setFontSize(new Dimension([
                    'magnitude' => $fontSize,
                    'unit' => 'PT',
                ]));
                $fields[] = 'fontSize';
            }

            $hexColor = $this->normalizeColorToHex($attrs['color'] ?? null);
            if ($hexColor !== null) {
                $textStyle->setForegroundColor(new Docs\OptionalColor([
                    'color' => new Docs\Color([
                        'rgbColor' => $this->hexToRgb($hexColor),
                    ]),
                ]));
                $fields[] = 'foregroundColor';
            }

            if (!empty($fields)) {
                $requests[] = new DocsRequest([
                    'updateTextStyle' => new UpdateTextStyleRequest([
                        'range' => new Range([
                            'startIndex' => $run['start'],
                            'endIndex' => $run['end'],
                        ]),
                        'textStyle' => $textStyle,
                        'fields' => implode(',', $fields),
                    ]),
                ]);
            }
        }

        $service->documents->batchUpdate($documentId, new BatchUpdateDocumentRequest(['requests' => $requests]));

        return $result;
    }

    /**
     * Applique du HTML à un document Google Docs
     */
    public function applyHtmlToDocument(string $documentId, string $html): void
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementsByTagName('div')->item(0);
        if (!$root instanceof \DOMElement) {
            return;
        }

        $segments = [];
        $this->collectSegments($root, $segments);

        if (empty($segments)) {
            return;
        }

        $fullText = '';
        $textStyles = [];
        $paragraphStyles = [];
        $currentIndex = 1;

        foreach ($segments as $seg) {
            $text = $seg['text'];
            $len = mb_strlen($text);
            if ($len === 0) continue;

            $start = $currentIndex;
            $end = $currentIndex + $len;
            $fullText .= $text;

            if (!empty($seg['textStyle'])) {
                $textStyles[] = ['range' => ['start' => $start, 'end' => $end], 'style' => $seg['textStyle']];
            }
            
            // On enregistre les styles de paragraphe pour chaque segment qui se termine par \n
            if (str_ends_with($text, "\n") || $text === "\n") {
                $paragraphStyles[] = ['range' => ['start' => $start, 'end' => $end], 'style' => $seg['paragraphStyle']];
            } else {
                // Pour les segments de texte, on veut aussi qu'ils appartiennent au bon style de paragraphe
                $paragraphStyles[] = ['range' => ['start' => $start, 'end' => $end], 'style' => $seg['paragraphStyle']];
            }

            $currentIndex += $len;
        }

        $client = $this->getClient();
        $service = new Docs($client);
        $requests = [];

        // 1. Une seule grosse insertion
        $requests[] = new DocsRequest([
            'insertText' => new InsertTextRequest([
                'location' => new Location(['index' => 1]),
                'text' => $fullText
            ])
        ]);

        // 2. Appliquer les styles de texte
        foreach ($textStyles as $ts) {
            $style = new TextStyle();
            $fields = [];
            if (isset($ts['style']['bold'])) { $style->setBold(true); $fields[] = 'bold'; }
            if (isset($ts['style']['italic'])) { $style->setItalic(true); $fields[] = 'italic'; }
            if (isset($ts['style']['underline'])) { $style->setUnderline(true); $fields[] = 'underline'; }
            $color = $ts['style']['color'] ?? null;
            if (is_string($color)) {
                $style->setForegroundColor(new Docs\OptionalColor(['color' => new Docs\Color(['rgbColor' => $this->hexToRgb($color)])]));
                $fields[] = 'foregroundColor';
            }

            if (!empty($fields)) {
                $requests[] = new DocsRequest([
                    'updateTextStyle' => new UpdateTextStyleRequest([
                        'range' => new Range(['startIndex' => $ts['range']['start'], 'endIndex' => $ts['range']['end']]),
                        'textStyle' => $style,
                        'fields' => implode(',', $fields)
                    ])
                ]);
            }
        }

        // 3. Appliquer les styles de paragraphe
        foreach ($paragraphStyles as $ps) {
            $requests[] = new DocsRequest([
                'updateParagraphStyle' => new Docs\UpdateParagraphStyleRequest([
                    'range' => new Range(['startIndex' => $ps['range']['start'], 'endIndex' => $ps['range']['end']]),
                    'paragraphStyle' => new Docs\ParagraphStyle(['namedStyleType' => $ps['style']]),
                    'fields' => 'namedStyleType'
                ])
            ]);
        }

        $batchUpdateRequest = new BatchUpdateDocumentRequest(['requests' => $requests]);
        $service->documents->batchUpdate($documentId, $batchUpdateRequest);
    }

    /**
     * @param array<int, array{text: string, textStyle: array<string, bool|string>, paragraphStyle: string}> $segments
     * @param array<string, bool|string> $currentTextStyle
     */
    private function collectSegments(
        \DOMNode $node,
        array &$segments,
        array $currentTextStyle = [],
        string $currentParagraphStyle = 'NORMAL_TEXT'
    ): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $content = $child->textContent;
                if ($content !== '') {
                    $segments[] = [
                        'text' => $content,
                        'textStyle' => $currentTextStyle,
                        'paragraphStyle' => $currentParagraphStyle
                    ];
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $newTextStyle = $currentTextStyle;
                $newParagraphStyle = $currentParagraphStyle;
                $isBlock = false;
                $tagName = strtolower($child->nodeName);

                switch ($tagName) {
                    case 'b': case 'strong': $newTextStyle['bold'] = true; break;
                    case 'i': case 'em': $newTextStyle['italic'] = true; break;
                    case 'u': $newTextStyle['underline'] = true; break;
                    case 'h1': $newParagraphStyle = 'HEADING_1'; $isBlock = true; break;
                    case 'h2': $newParagraphStyle = 'HEADING_2'; $isBlock = true; break;
                    case 'h3': $newParagraphStyle = 'HEADING_3'; $isBlock = true; break;
                    case 'p': case 'div': $isBlock = true; break;
                }

                if ($child->hasAttributes()) {
                    $styleAttr = $child->attributes->getNamedItem('style');
                    if ($styleAttr) {
                        $styleAttrValue = (string) $styleAttr->nodeValue;
                        if (preg_match('/color:\s*rgb\((\d+),\s*(\d+),\s*(\d+)\)/', $styleAttrValue, $matches)) {
                            $newTextStyle['color'] = sprintf("#%02x%02x%02x", $matches[1], $matches[2], $matches[3]);
                        } elseif (preg_match('/color:\s*(#[0-9a-fA-F]{6})/', $styleAttrValue, $matches)) {
                            $newTextStyle['color'] = $matches[1];
                        }
                    }
                }

                $this->collectSegments($child, $segments, $newTextStyle, $newParagraphStyle);

                if ($isBlock || $tagName === 'br') {
                    $segments[] = [
                        'text' => "\n",
                        'textStyle' => [],
                        'paragraphStyle' => $newParagraphStyle
                    ];
                }
            }
        }
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

    private function utf16Length(string $text): int
    {
        return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
    }

    /**
     * @return 'HEADING_1'|'HEADING_2'|'HEADING_3'|'HEADING_4'|'HEADING_5'|'HEADING_6'|null
     */
    private function quillHeaderToNamedStyleType(mixed $header): ?string
    {
        $value = is_numeric($header) ? (int) $header : null;

        return match ($value) {
            1 => 'HEADING_1',
            2 => 'HEADING_2',
            3 => 'HEADING_3',
            4 => 'HEADING_4',
            5 => 'HEADING_5',
            6 => 'HEADING_6',
            default => null,
        };
    }

    /**
     * @return 'CENTER'|'END'|'JUSTIFIED'|'START'|null
     */
    private function quillAlignToAlignment(mixed $align): ?string
    {
        if (!is_string($align)) {
            return null;
        }

        return match (strtolower(trim($align))) {
            'center' => 'CENTER',
            'right' => 'END',
            'justify' => 'JUSTIFIED',
            'left' => 'START',
            default => null,
        };
    }

    /**
     * @return 'BULLET_DISC_CIRCLE_SQUARE'|'NUMBERED_DECIMAL_ALPHA_ROMAN'|null
     */
    private function quillListToBulletPreset(mixed $list): ?string
    {
        if (!is_string($list)) {
            return null;
        }

        return match (strtolower(trim($list))) {
            'bullet' => 'BULLET_DISC_CIRCLE_SQUARE',
            'ordered' => 'NUMBERED_DECIMAL_ALPHA_ROMAN',
            default => null,
        };
    }

    private function quillSizeToPt(mixed $size): ?float
    {
        if ($size === null) {
            return null;
        }

        if (is_numeric($size)) {
            return (float) $size;
        }

        if (!is_string($size) || $size === '') {
            return null;
        }

        $normalized = strtolower(trim($size));

        return match ($normalized) {
            'small' => 10.0,
            'large' => 16.0,
            'huge' => 22.0,
            default => preg_match('/^(\d+(?:\.\d+)?)px$/', $normalized, $matches) ? (float) $matches[1] * 0.75 : null,
        };
    }

    private function normalizeColorToHex(mixed $color): ?string
    {
        if (!is_string($color)) {
            return null;
        }

        $value = trim($color);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return strtoupper($value);
        }

        if (preg_match('/^rgb\((\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})\)$/i', $value, $matches)) {
            $r = max(0, min(255, (int) $matches[1]));
            $g = max(0, min(255, (int) $matches[2]));
            $b = max(0, min(255, (int) $matches[3]));

            return sprintf('#%02X%02X%02X', $r, $g, $b);
        }

        return null;
    }
}
