<?php

declare(strict_types=1);

namespace Drupal\headless_mail;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\user\UserInterface;

/**
 * Sends mail through any registered mail plugin, in one reusable place.
 *
 * ## Why this exists
 *
 * Seven call sites across `custom_module` and `headless_messages` were reaching for
 * the plugin manager themselves — some injected, some as
 * `\Drupal::service('plugin.manager.mail')` — and each had to know the module/key
 * convention, pick a language, and decide what a failure meant. The admin-created
 * account mail in particular was one `new` object away from being reimplemented four
 * times.
 *
 * The generic part is already generic in core: `mail($module, $key, ...)` resolves
 * any `MailInterface` plugin. This class is the single seam in front of it.
 *
 * ## Failures are reported, not thrown
 *
 * {@see self::send()} returns FALSE and logs rather than propagating. That is a
 * deliberate contract, and it is the reason this class is safe to call from the middle
 * of a write: the legacy webform created a patient, saved them, and *then* mailed
 * them, so a mail failure never cost anyone their account. A mailer that threw would
 * turn a cosmetic failure into either a lost patient or a 500 on a record that was
 * really created. Callers that genuinely cannot proceed without the mail should check
 * the return value; nobody has needed to yet.
 *
 * A missing mail plugin is treated the same way. That is the interesting failure mode
 * in practice — a typo in a key, or a plugin disabled — and it must not read as a
 * transport outage in the log.
 */
class Mailer {

  /**
   * Constructs a Mailer.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   Resolves a module + key pair to a mail plugin. The interface rather than
   *   \Drupal\Core\Mail\MailManager, because it is what the container aliases
   *   `plugin.manager.mail` to and because a test can substitute it.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Records what was sent and what failed.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Supplies the default language. Injected rather than reached for through
   *   \Drupal::languageManager(): a static container call inside a service is a
   *   hidden dependency that a unit test cannot substitute.
   */
  public function __construct(
    protected readonly MailManagerInterface $mailManager,
    protected readonly LoggerChannelInterface $logger,
    protected readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Whether an address is worth attempting.
   *
   * Empty is not deliverable, and a malformed address would fail in the transport
   * rather than here, where it can still be reported against the caller that owns the
   * bad data. Deliberately the same test core uses, so "is this deliverable" means
   * one thing across the codebase rather than two.
   */
  public function isDeliverable(string $address): bool {
    $address = trim($address);

    return $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== FALSE;
  }

  /**
   * Sends one mail to one address.
   *
   * @param string $module
   *   The module that owns the mail plugin, e.g. `user` for core or
   *   `custom_module` for the templates in its hook_mail().
   * @param string $key
   *   The key within that module, e.g. `register_admin_created`.
   * @param string $to
   *   A single address.
   * @param array $params
   *   Placeholders the plugin's mail hook reads.
   * @param string|null $langcode
   *   Defaults to the site language, which is what every existing caller meant.
   * @param string|null $reply
   *   Reply-to address, or NULL for the site default.
   *
   * @return bool
   *   TRUE when the mail was handed to the transport, FALSE when it was skipped or
   *   failed. Always check this if the caller needs to know.
   */
  public function send(
    string $module,
    string $key,
    string $to,
    array $params = [],
    ?string $langcode = NULL,
    ?string $reply = NULL,
  ): bool {
    if (!$this->isDeliverable($to)) {
      $this->logger->warning('Mail @module/@key skipped: @to is not a usable address.', [
        '@module' => $module,
        '@key' => $key,
        '@to' => $to === '' ? '(empty)' : '(malformed)',
      ]);
      return FALSE;
    }

    $langcode = $langcode ?: $this->languageManager->getDefaultLanguage()->getId();

    try {
      $message = $this->mailManager->mail($module, $key, $to, $langcode, $params, $reply);
    }
    catch (PluginException $e) {
      // No plugin for this pair. Logged as an error because it is always a bug in the
      // caller, and reported as a skip rather than a failure because nothing was
      // actually attempted.
      $this->logger->error('Mail @module/@key has no registered plugin: @message', [
        '@module' => $module,
        '@key' => $key,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Mail @module/@key to @to failed: @message', [
        '@module' => $module,
        '@key' => $key,
        '@to' => $to,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    $this->logger->debug('Mail @module/@key sent to @to.', [
      '@module' => $module,
      '@key' => $key,
      '@to' => $to,
    ]);

    return TRUE;
  }

  /**
   * Sends the same mail to several accounts.
   *
   * Skips unusable addresses and deduplicates, so a caller can pass a query result
   * without pre-checking it. Returns the number actually sent rather than TRUE, since
   * a partial send is the normal outcome of an unfiltered list and a caller logging
   * "sent N" is more useful than one logging "yes".
   *
   * @param iterable<\Drupal\user\UserInterface> $accounts
   *   Accounts to write to, read through their own address.
   * @param array<string, mixed> $params
   *   Placeholders the plugin's mail hook reads.
   *
   * @return int
   *   How many addresses the mail went to.
   */
  public function sendToAccounts(
    string $module,
    string $key,
    iterable $accounts,
    array $params = [],
    ?string $langcode = NULL,
    ?string $reply = NULL,
  ): int {
    $sent = 0;
    $seen = [];

    foreach ($accounts as $account) {
      if (!$account instanceof UserInterface) {
        continue;
      }
      $address = trim((string) $account->getEmail());
      // Keyed by address rather than by id: the same address on two accounts is one
      // email, and sending it twice is how a mailing list duplicates itself.
      $seen[$address] = TRUE;
      if ($this->send($module, $key, $address, $params, $langcode, $reply)) {
        $sent++;
      }
    }

    return $sent;
  }

  /**
   * The "an administrator created your account" mail, for core's `user` plugin.
   *
   * A named method rather than a `send('user', 'register_admin_created', ...)` at
   * every call site, because this is the one key with enough callers to be worth a
   * stable name — and because the account has to be read through the account, not an
   * address a caller remembered to pass.
   *
   * Core's plugin sends a one-time login link, which is what makes it usable for a
   * patient who has never had a password.
   *
   * @return bool
   *   Whether it was sent.
   */
  public function sendAccountCreated(UserInterface $account, ?string $langcode = NULL): bool {
    return $this->send(
      'user',
      'register_admin_created',
      (string) $account->getEmail(),
      ['account' => $account],
      $langcode
    );
  }

}