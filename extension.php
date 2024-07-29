<?php

declare(strict_types=1);

/**
 * Classe d'assistance pour accéder aux propriétés privées
 */
class FreshRSS_Helper
{
    /**
     * Accède à une propriété privée d'un objet en utilisant la réflexion
     *
     * @param object $object L'objet dont la propriété est à extraire
     * @param string $propertyName Le nom de la propriété à extraire
     * @return mixed La valeur de la propriété demandée
     */
    public static function getProperty(object $object, string $propertyName)
    {
        $reflectionClass = new ReflectionClass($object);
        $property = $reflectionClass->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }
}

/**
 * Classe principale pour l'extension FreshRSS vers NTFY
 */
final class FreshRSSToNTFYExtension extends Minz_Extension
{
    /** @var string URL par défaut pour NTFY */
    private const DEFAULT_NTFY_URL = 'https://notify.targate.xyz/';

    /** @var string Sujet par défaut pour NTFY */
    private const DEFAULT_NTFY_TOPIC = 'redmine';

    /**
     * Méthode d'initialisation de l'extension
     *
     * @throws FreshRSS_Context_Exception Si la configuration système n'est pas initialisée
     */
    #[\Override]
    public function init(): void
    {
        parent::init();

        $this->registerTranslates();

        if (!FreshRSS_Context::hasUserConf()) {
            throw new FreshRSS_Context_Exception('User configuration not initialised!');
        }

        $this->initializeUserConfiguration();
        // Enregistrement du hook pour capturer les nouveaux articles
        $this->registerHook('entry_before_insert', [$this, 'notifyNewEntry']);
    }


    /**
     * Initialise la configuration utilisateur avec des valeurs par défaut si nécessaire
     */
    private function initializeUserConfiguration(): void
    {
        $userConf = FreshRSS_Context::userConf();
        $save = false;

        // Vérifie si l'URL NTFY est définie, sinon utilise la valeur par défaut
        if ($this->isEmpty($userConf->attributeString('ntfy_url'))) {
            $userConf->attributeString('ntfy_url', self::DEFAULT_NTFY_URL);
            $save = true;
        }

        // Vérifie si le sujet NTFY est défini, sinon utilise la valeur par défaut
        if ($this->isEmpty($userConf->attributeString('ntfy_topic'))) {
            $userConf->attributeString('ntfy_topic', self::DEFAULT_NTFY_TOPIC);
            $save = true;
        }

        if ($save) {
            $userConf->save();
        }
    }

    /**
     * Vérifie si une chaîne est vide ou null
     *
     * @param ?string $value La valeur à vérifier
     * @return bool Vrai si la chaîne est vide ou null, sinon faux
     */
    private function isEmpty(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * Méthode appelée pour gérer l'action de configuration
     */
    #[\Override]
    public function handleConfigureAction(): void
    {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            $this->saveConfigurationFromRequest();
        }
    }

    /**
     * Sauvegarde la configuration à partir de la requête HTTP POST
     */
    private function saveConfigurationFromRequest(): void
    {
        $userConf = FreshRSS_Context::userConf();

        $ntfy_url = Minz_Request::paramString('ntfy_url', true) ?: self::DEFAULT_NTFY_URL;
        $ntfy_topic = Minz_Request::paramString('ntfy_topic', true) ?: self::DEFAULT_NTFY_TOPIC;

        $userConf->attributeString('ntfy_url', $ntfy_url);
        $userConf->attributeString('ntfy_topic', $ntfy_topic);

        $userConf->save();
    }

    /**
     * Getter pour obtenir l'URL NTFY
     *
     * @return string L'URL actuelle de NTFY
     */
    public function getNtfyUrl(): string
    {
        if (!class_exists('FreshRSS_Context', false) || !FreshRSS_Context::hasUserConf()) {
            return self::DEFAULT_NTFY_URL;
        }
        return FreshRSS_Context::userConf()->attributeString('ntfy_url') ?? self::DEFAULT_NTFY_URL;
    }

    /**
     * Getter pour obtenir le sujet NTFY
     *
     * @return string Le sujet actuel de NTFY
     */
    public function getNtfyTopic(): string
    {
        if (!class_exists('FreshRSS_Context', false) || !FreshRSS_Context::hasUserConf()) {
            return self::DEFAULT_NTFY_TOPIC;
        }
        return FreshRSS_Context::userConf()->attributeString('ntfy_topic') ?? self::DEFAULT_NTFY_TOPIC;
    }

    /**
     * Méthode appelée avant l'insertion d'une nouvelle entrée
     * Envoie une notification pour chaque nouvelle entrée
     *
     * @param FreshRSS_Entry $entry L'entrée nouvellement ajoutée
     * @return FreshRSS_Entry L'entrée sans modification
     */
    public function notifyNewEntry(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        $message = $this->createNotificationMessage($entry);
        $headers = $this->createNotificationHeaders($entry);

        $this->sendNotification($message, $headers);

        return $entry;
    }

    /**
     * Crée le message de notification pour une entrée donnée
     *
     * @param FreshRSS_Entry $entry L'entrée pour laquelle le message est créé
     * @return string Le message formaté pour la notification
     */
    private function createNotificationMessage(FreshRSS_Entry $entry): string
    {
        $description = strip_tags(FreshRSS_Helper::getProperty($entry, 'content')) ?? _t('ext.ntfy_code.no_content');
        $pubDate = FreshRSS_Helper::getProperty($entry, 'date') ?? _t('ext.ntfy_code.no_date');
        return sprintf(
            "Description : %s\nDate de publication : %s\n",
            $description,
            date('c', $pubDate), // Format de la date en date FR 30-07-2024 à 00h47
        );
    }

    /**
     * Crée les en-têtes de notification pour une entrée donnée
     *
     * @param FreshRSS_Entry $entry L'entrée pour laquelle les en-têtes sont créés
     * @return array Les en-têtes formatés pour la notification
     */
    private function createNotificationHeaders(FreshRSS_Entry $entry): array
    {
        $enclosureUrl = $this->getEnclosureUrl($entry);
        headers == array();
        // headers['Content-Type'] == 'text/plain';
        headers['Content-Type'] == 'text/markdown';
        if (! $this->isEmpty(FreshRSS_Helper::getProperty($entry, 'link'))) {
            headers['X-Click'] == FreshRSS_Helper::getProperty($entry, 'link');
        }
        if (! $this->isEmpty(FreshRSS_Helper::getProperty($entry, 'Title'))) {
            headers['X-Title'] == FreshRSS_Helper::getProperty($entry, 'Title');
        }
        if (! $this->isEmpty(FreshRSS_Helper::getProperty($entry, 'Tags'))) {
            headers['X-Tags'] == FreshRSS_Helper::getProperty($entry, 'Tags');
        }
        if (! $this->isEmpty($enclosureUrl)) {
            headers['X-Attach'] == $enclosureUrl;
        }
        return $headers;
    }

    /**
     * Récupère l'URL de l'enclosure pour une entrée donnée
     *
     * @param FreshRSS_Entry $entry L'entrée pour laquelle l'URL est récupérée
     * @return ?string L'URL de l'enclosure, ou null si non disponible
     */
    private function getEnclosureUrl(FreshRSS_Entry $entry): ?string
    {
        $enclosures = FreshRSS_Helper::getProperty($entry, 'attributes')['enclosures'] ?? null;
        return !empty($enclosures) ? $enclosures[0]['url'] : null;
    }

    /**
     * Envoie une notification à NTFY avec les données fournies
     *
     * @param string $message Le message à envoyer
     * @param array $headers Les en-têtes HTTP à inclure dans la requête
     */
    private function sendNotification(string $message, array $headers): void
    {
        $url = rtrim($this->getNtfyUrl(), '/') . '/' . $this->getNtfyTopic();

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $message,
            ],
        ];

        $context = stream_context_create($options);

        // Gestion des erreurs lors de l'envoi de la notification
        try {
            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                $this->handleError($http_response_header ?? [], $message);
            }
        } catch (Exception $e) {
            Minz_Log::error('Failed to send notification to NTFY: ' . $e->getMessage());
        }
    }

    /**
     * Gère les erreurs lors de l'envoi de la notification
     *
     * @param array $responseHeaders Les en-têtes de réponse HTTP
     * @param string $message Le message de notification
     */
    private function handleError(array $responseHeaders, string $message): void
    {
        $statusCode = 0;
        if (!empty($responseHeaders[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $responseHeaders[0], $matches);
            $statusCode = isset($matches[1]) ? (int)$matches[1] : 0;
        }

        Minz_Log::error(sprintf('Notification failed with status %d: %s', $statusCode, $message));
    }
}
