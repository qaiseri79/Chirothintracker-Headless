<?php

namespace Drupal\commerce_authnet;

use CommerceGuys\AuthNet\Response\ResponseInterface;

/**
 * Translates Authorize.net exceptions and errors into Commerce exceptions.
 */
class ErrorHelper {

  /**
   * Writes an API response to the log for debugging.
   *
   * @param \CommerceGuys\AuthNet\Response\ResponseInterface $response
   *   The API response object.
   */
  public static function logResponse(ResponseInterface $response): void {
    $message = self::describeResponse($response);
    $level = $response->getResultCode() === 'Error' ? 'error' : 'info';
    \Drupal::logger('commerce_authnet')->log($level, $message);
  }

  /**
   * Formats an API response as a string.
   *
   * @param \CommerceGuys\AuthNet\Response\ResponseInterface $response
   *   The API response object.
   *
   * @return string
   *   The message.
   */
  public static function describeResponse(ResponseInterface $response): string {
    $messages = [];
    foreach ($response->getMessages() as $message) {
      $messages[] = $message->getCode() . ': ' . $message->getText();
    }

    return t('Received response with code %code from Authorize.net: @messages', [
      '%code' => $response->getResultCode(),
      '@messages' => implode("\n", $messages),
    ]);
  }

}
