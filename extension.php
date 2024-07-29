<?php
declare(strict_types=1);

// Classe d'assistance pour accéder aux propriétés privées
class FreshRSS_Helper
{
    /**
     * Accède à une propriété privée d'un objet en utilisant la réflexion
     *
     * @param object $object L'objet dont la propriété est à extraire
     * @param string $propertyName Le nom de la propriété à extraire
     * @return mixed La valeur de la propriété demandée
     */
    public static function getProperty($object, $propertyName)
    {
        $reflectionClass = new ReflectionClass($object);
        $property = $reflectionClass->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }
}

// Classe principale pour l'extension FreshRSS vers NTFY
final class FreshRSSToNTFYExtension extends Minz_Extension
{
    private const DEFAULT_NTFY_URL = 'https://notify.targate.xyz/redmine'; // URL par défaut pour NTFY
    private const DEFAULT_NTFY_TOPIC = 'RSS'; // Sujet par défaut pour NTFY

    private string $ntfy_url;   // URL NTFY personnalisée
    private string $ntfy_topic; // Sujet NTFY personnalisé

    /**
     * Méthode d'initialisation de l'extension
     */
    #[\Override]
    public function init(): void
    {
        parent::init();  // Appel de l'initialisation de la classe parente

        $this->registerTranslates();  // Enregistre les traductions nécessaires

        // Vérification que la configuration utilisateur est disponible
        if (!FreshRSS_Context::hasUserConf()) {
            throw new FreshRSS_Context_Exception('System configuration not initialised!');
        }

        // Chargement des configurations utilisateurs
        $this->loadConfigValues();

        // Enregistrement du hook pour capturer les nouveaux articles
        $this->registerHook('entry_before_insert', [$this, 'notifyNewEntry']);
        
        // Chargement des configurations utilisateurs et application des valeurs par défaut si nécessaire
        $userConf = FreshRSS_Context::userConf();
        $save = false; // Variable pour suivre si une sauvegarde de la configuration est nécessaire

        // Vérifie si l'URL NTFY est définie, sinon utilise la valeur par défaut
        if (is_null($userConf->attributeString('ntfy_url'))) {
            $userConf->attributeString('ntfy_url', self::DEFAULT_NTFY_URL);
            $save = true;
        }

        // Vérifie si le sujet NTFY est défini, sinon utilise la valeur par défaut
        if (is_null($userConf->attributeString('ntfy_topic'))) {
            $userConf->attributeString('ntfy_topic', self::DEFAULT_NTFY_TOPIC);
            $save = true;
        }

        // Si des changements ont été effectués, sauvegarde les configurations
        if ($save) {
            $userConf->save();
        }
    }

    /**
     * Cette fonction est appelée par FreshRSS lorsque la page de configuration est chargée
     * et lors de l'enregistrement de la configuration.
     *  - Enregistre la configuration en cas de POST.
     *  - (Re)charge la configuration dans tous les cas, pour synchroniser après un enregistrement et avant un chargement de page.
     */
    #[\Override]
    public function handleConfigureAction(): void
    {
        $this->registerTranslates();  // Enregistrement des traductions

        // Vérifie si la requête est un POST, ce qui signifie que l'utilisateur soumet des paramètres
        if (Minz_Request::isPost()) {
            $userConf = FreshRSS_Context::userConf();

            // Sauvegarde des configurations utilisateur avec les nouvelles valeurs ou les valeurs par défaut
            $userConf->attributeString('ntfy_url', Minz_Request::paramString('ntfy_url', true) ?: self::DEFAULT_NTFY_URL);
            $userConf->attributeString('ntfy_topic', Minz_Request::paramString('ntfy_topic', true) ?: self::DEFAULT_NTFY_TOPIC);

            $userConf->save();  // Sauvegarde les nouvelles configurations
        }
        
        // Charge les valeurs de configuration
        $this->loadConfigValues();
    }

    /**
     * Initialise la configuration de l'extension si le contexte utilisateur est disponible.
     * Ne pas appeler cette méthode dans init(), elle ne peut pas être utilisée à cet endroit.
     */
    public function loadConfigValues(): void
    {
        // Vérifie si le contexte utilisateur est disponible
        if (!class_exists('FreshRSS_Context', false) || !FreshRSS_Context::hasUserConf()) {
            return;
        }

        // Chargement des valeurs de configuration pour l'URL NTFY
        $ntfy_url = FreshRSS_Context::userConf()->attributeString('ntfy_url');
        if ($ntfy_url !== null) {
            $this->ntfy_url = $ntfy_url;
        }

        // Chargement des valeurs de configuration pour le sujet NTFY
        $ntfy_topic = FreshRSS_Context::userConf()->attributeString('ntfy_topic');
        if ($ntfy_topic !== null) {
            $this->ntfy_topic = $ntfy_topic;
        }
    }

    /**
     * Getter pour obtenir l'URL NTFY
     *
     * @return string L'URL actuelle de NTFY
     */
    public function getNtfyUrl(): string
    {
        return $this->ntfy_url;
    }

    /**
     * Getter pour obtenir le sujet NTFY
     *
     * @return string Le sujet actuel de NTFY
     */
    public function getNtfyTopic(): string
    {
        return $this->ntfy_topic;
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
        // Récupère les valeurs à partir de l'entrée
        $title = FreshRSS_Helper::getProperty($entry, 'title');
        $url = FreshRSS_Helper::getProperty($entry, 'link');
        $description = strip_tags(FreshRSS_Helper::getProperty($entry, 'content'))?? _t('ext.ntfy.nocontent');
        $pubDate = FreshRSS_Helper::getProperty($entry, 'date')?? _t('ext.ntfy.nodate');
        $tags = implode(', ', FreshRSS_Helper::getProperty($entry, 'tags'));
        $enclosures = FreshRSS_Helper::getProperty($entry, 'attributes')['enclosures']?? [];
        $enclosureUrl = !empty($enclosures)? $enclosures[0]['url'] : null;

        // Définit les en-têtes de la requête HTTP
        $headers = [];
            // 'Content-Type: text/plain',
            // 'Click: https://home.nest.com/',
            // 'Attach: ' . $enclosureUrl,
            // 'Actions: http, Open door, https://api.nest.com/open/yAxkasd, clear=true',
            // 'Email: phil@example.com'
        // ];

        // Prépare le message pour NTFY avec les détails de l'article
        $message = sprintf(
            "Titre : %s\nURL : %s\nDescription : %s\nDate de publication : %s\nTags : %s\nEnclosure URL : %s\n",
            $title,
            $url,
            $description,
            date('c', $pubDate), // Format de la date ISO 8601
            $tags,
            $enclosureUrl
        );

        // Envoie la notification à l'URL NTFY
        $this->sendNotification($message);

        // Retourne l'entrée inchangée
        return $entry;
    }

    /**
     * Envoie une notification au service NTFY
     *
     * @param string $message Le message à envoyer dans la notification
     */
    private function sendNotification(string $message, string $headers): void
    {
        $topic = $this->getNtfyTopic();  // Obtient le sujet NTFY via la méthode d'instance
        $url = $this->getNtfyUrl();  // Formate l'URL NTFY complète



        $ch = curl_init($url);  // Initialise une session cURL
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");  // Définit la requête comme un POST
        curl_setopt($ch, CURLOPT_POSTFIELDS, $message);  // Définit le corps de la requête avec le message
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  // Retourne le transfert en tant que chaîne de caractères
        // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); // Optionnel : ajoute les en-têtes HTTP

        $response = curl_exec($ch);  // Exécute la requête cURL

        // Vérifie si une erreur cURL est survenue
        if (curl_errno($ch)) {
            Minz_Log::warning('Erreur CURL: ' . $url . '\n' . curl_error($ch));  // Log l'erreur cURL
        } else {
            // Optionnel : log de la réponse pour débogage
            Minz_Log::warning('Réponse NTFY: ' . $url . '\n' . $response);  // Log la réponse de NTFY
        }

        curl_close($ch);  // Ferme la session cURL
    }
}
