<?php

namespace Drupal\ctt_gohighlevel\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for GoHighLevel API integration.
 */
class GoHighLevelApiService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * GoHighLevel API base URL.
   *
   * @var string
   */
  protected $apiBaseUrl = 'https://services.leadconnectorhq.com';

  /**
   * GoHighLevel API version.
   *
   * @var string
   */
  protected $apiVersion = '2021-07-28';

  /**
   * Constructs a GoHighLevelApiService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('ctt_gohighlevel');
    $this->configFactory = $config_factory;
  }

  /**
   * Get API key from configuration.
   *
   * @return string|null
   *   The API key or NULL if not configured.
   */
  protected function getApiKey() {
    $config = $this->configFactory->get('ctt_gohighlevel.settings');
    return $config->get('api_key');
  }

  /**
   * Get Location ID from configuration.
   *
   * @return string|null
   *   The Location ID or NULL if not configured.
   */
  protected function getLocationId() {
    $config = $this->configFactory->get('ctt_gohighlevel.settings');
    return $config->get('location_id');
  }

  /**
   * Get common headers for API requests.
   *
   * @return array
   *   Array of headers.
   */
  protected function getHeaders() {
    return [
      'Authorization' => 'Bearer ' . $this->getApiKey(),
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
      'Version' => $this->apiVersion,
    ];
  }

  /**
   * Search for a contact by email.
   *
   * @param string $email
   *   The email address to search for.
   *
   * @return array|null
   *   Contact data if found, NULL otherwise.
   */
  public function getContactByEmail($email) {
    $api_key = $this->getApiKey();
    $location_id = $this->getLocationId();
    
    if (empty($api_key) || empty($location_id)) {
      $this->logger->error('GoHighLevel API key or Location ID is not configured.');
      return NULL;
    }

    if (empty($email)) {
      $this->logger->error('Email is required to search for a contact.');
      return NULL;
    }

    try {
      $response = $this->httpClient->request('GET', $this->apiBaseUrl . '/contacts/', [
        'headers' => $this->getHeaders(),
        'query' => [
          'locationId' => $location_id,
          'query' => $email,
        ],
        'timeout' => 30,
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);
      
      // Check if we got any contacts.
      if (!empty($body['contacts']) && is_array($body['contacts'])) {
        // Find exact email match.
        foreach ($body['contacts'] as $contact) {
          if (isset($contact['email']) && strtolower($contact['email']) === strtolower($email)) {
            $this->logger->info('Contact found in GoHighLevel: @email', [
              '@email' => $email,
            ]);
            return $contact;
          }
        }
      }
      
      // No exact match found.
      $this->logger->info('No contact found in GoHighLevel for email: @email', [
        '@email' => $email,
      ]);
      return NULL;
    }
    catch (RequestException $e) {
      $error_message = $e->getMessage();
      
      // Try to get more detailed error from response.
      if ($e->hasResponse()) {
        $error_body = $e->getResponse()->getBody()->getContents();
        $error_data = json_decode($error_body, TRUE);
        if (isset($error_data['message'])) {
          $error_message = $error_data['message'];
        }
      }
      
      $this->logger->error('Failed to search contact in GoHighLevel: @message', [
        '@message' => $error_message,
      ]);
      
      return NULL;
    }
  }

  /**
   * Create or update a contact in GoHighLevel.
   *
   * @param array $data
   *   Contact data including:
   *   - email (required)
   *   - firstName
   *   - lastName
   *   - phone
   *   - tags (array)
   *   - customFields (array)
   *   - source
   *   - address1
   *   - city
   *   - state
   *   - postalCode
   *   - country
   *
   * @return array|bool
   *   Response data or FALSE on failure.
   */
  public function createContact(array $data) {
    $api_key = $this->getApiKey();
    
    if (empty($api_key)) {
      $this->logger->error('GoHighLevel API key is not configured.');
      return FALSE;
    }

    if (empty($data['email'])) {
      $this->logger->error('Email is required to create a contact.');
      return FALSE;
    }

    // Add location ID if configured.
    $location_id = $this->getLocationId();
    if (!empty($location_id)) {
      $data['locationId'] = $location_id;
    }

    try {
      $response = $this->httpClient->request('POST', $this->apiBaseUrl . '/contacts/', [
        'headers' => $this->getHeaders(),
        'json' => $data,
        'timeout' => 30,
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);
      
      $this->logger->info('Contact created/updated in GoHighLevel: @email', [
        '@email' => $data['email'],
      ]);

      return $body;
    }
    catch (RequestException $e) {
      $error_message = $e->getMessage();
      
      // Try to get more detailed error from response.
      if ($e->hasResponse()) {
        $error_body = $e->getResponse()->getBody()->getContents();
        $error_data = json_decode($error_body, TRUE);
        if (isset($error_data['message'])) {
          $error_message = $error_data['message'];
        }
      }
      
      $this->logger->error('Failed to create contact in GoHighLevel: @message', [
        '@message' => $error_message,
      ]);
      
      return FALSE;
    }
  }

  /**
   * Update an existing contact in GoHighLevel.
   *
   * @param string $contact_id
   *   The GoHighLevel contact ID.
   * @param array $data
   *   Contact data to update including:
   *   - firstName
   *   - lastName
   *   - phone
   *   - email
   *   - tags (array)
   *   - customFields (array)
   *   - address1
   *   - city
   *   - state
   *   - postalCode
   *   - country
   *
   * @return array|bool
   *   Response data or FALSE on failure.
   */
  public function updateContact($contact_id, array $data) {
    $api_key = $this->getApiKey();
    
    if (empty($api_key)) {
      $this->logger->error('GoHighLevel API key is not configured.');
      return FALSE;
    }

    if (empty($contact_id)) {
      $this->logger->error('Contact ID is required to update a contact.');
      return FALSE;
    }

    try {
      $response = $this->httpClient->request('PUT', $this->apiBaseUrl . '/contacts/' . $contact_id, [
        'headers' => $this->getHeaders(),
        'json' => $data,
        'timeout' => 30,
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);
      
      $this->logger->info('Contact updated in GoHighLevel: @id', [
        '@id' => $contact_id,
      ]);

      return $body;
    }
    catch (RequestException $e) {
      $error_message = $e->getMessage();
      
      // Try to get more detailed error from response.
      if ($e->hasResponse()) {
        $error_body = $e->getResponse()->getBody()->getContents();
        $error_data = json_decode($error_body, TRUE);
        if (isset($error_data['message'])) {
          $error_message = $error_data['message'];
        }
      }
      
      $this->logger->error('Failed to update contact in GoHighLevel: @message', [
        '@message' => $error_message,
      ]);
      
      return FALSE;
    }
  }

  /**
   * Test API connection.
   *
   * @return bool
   *   TRUE if connection is successful, FALSE otherwise.
   */
  public function testConnection() {
    $api_key = $this->getApiKey();
    $location_id = $this->getLocationId();
    
    if (empty($api_key)) {
      $this->logger->error('API key is not configured for connection test.');
      return FALSE;
    }

    if (empty($location_id)) {
      $this->logger->error('Location ID is required for connection test.');
      return FALSE;
    }

    try {
      // Try to access the contacts endpoint with locationId and a limit of 1.
      $response = $this->httpClient->request('GET', $this->apiBaseUrl . '/contacts/', [
        'headers' => $this->getHeaders(),
        'query' => [
          'locationId' => $location_id,
          'limit' => 1,
        ],
        'timeout' => 10,
      ]);

      $status_code = $response->getStatusCode();
      
      if ($status_code === 200) {
        $this->logger->info('API connection test successful.');
        return TRUE;
      }
      
      $this->logger->warning('API connection test returned unexpected status code: @code', [
        '@code' => $status_code,
      ]);
      return FALSE;
    }
    catch (RequestException $e) {
      $error_message = $e->getMessage();
      
      // Try to get more detailed error from response.
      if ($e->hasResponse()) {
        $error_body = $e->getResponse()->getBody()->getContents();
        $error_data = json_decode($error_body, TRUE);
        if (isset($error_data['message'])) {
          $error_message = $error_data['message'];
        }
      }
      
      $this->logger->error('API connection test failed: @message', [
        '@message' => $error_message,
      ]);
      return FALSE;
    }
  }

}