# Djosend PHP SDK

Client PHP pour l'API Messages Djosend (`/api/v1/messages`). PHP 8.2 ou plus récent.

## Installation

```bash
composer require djosend/php-sdk
```

Le package devra être publié sur Packagist avant que cette commande soit disponible. Pour un projet local, ajouter un dépôt Composer de type `path` ou `vcs`.

## Envoyer et suivre un message

```php
<?php

require 'vendor/autoload.php';

use Djosend\DjosendClient;

$client = new DjosendClient($_ENV['DJOSEND_API_KEY']);
$message = $client->sendMessage([
    'channel' => 'sms',
    'to' => '+2250102030405',
    'body' => 'Bonjour depuis Djosend',
], bin2hex(random_bytes(16)));

// L'envoi est asynchrone : consulter ensuite son statut.
$details = $client->getMessage($message['id']);
echo $details['status'];
```

`sendMessage()` exige une clé d'idempotence de 1 à 128 caractères. Le SDK la transmet dans l'en-tête `Idempotency-Key` et réessaie uniquement les erreurs réseau, 429 et 5xx. La même clé est conservée à chaque tentative. Ne réutilisez jamais une clé avec un contenu différent. Les lectures et annulations ne sont pas réessayées automatiquement.

## Autres opérations

```php
$page = $client->listMessages(['page' => 1, 'limit' => 20, 'status' => 'delivered']);
$capabilities = $client->getChannelCapabilities();
$scheduled = $client->scheduleMessage([
    'to' => '+2250102030405',
    'body' => 'Rappel',
    'scheduledAt' => '2026-11-01T10:00:00Z',
    'timezone' => 'Africa/Abidjan',
], bin2hex(random_bytes(16)));
$client->cancelScheduledMessage($scheduled['id']);
```

Le SDK retourne les objets JSON de l'API sous forme de tableaux PHP, sans l'enveloppe `{ success, data }`. Les réponses HTTP en erreur lèvent `Djosend\Exception\ApiException` avec `statusCode`, `requestId` et `responseBody`. Les entrées invalides lèvent `ValidationException` et les erreurs réseau `TransportException`.

Pour la sandbox ou les tests, le constructeur accepte une origine HTTPS, un client Guzzle personnalisé et le nombre maximal de retries :

```php
$client = new DjosendClient($apiKey, 'https://sandbox.example', $guzzleClient, 2);
```

## Développement

```bash
composer install
composer test
```

Le code suit le contrat de `djosend-core-api` sur la branche locale consultée le 24 octobre 2026. Les routes SMS historiques (`/api/v1/sms/messages`) sont en cours de dépréciation et ne font pas partie de ce SDK.
