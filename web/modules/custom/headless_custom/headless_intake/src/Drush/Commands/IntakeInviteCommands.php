<?php

declare(strict_types=1);

namespace Drupal\headless_intake\Drush\Commands;

use Drupal\headless_intake\IntakeInviteService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for minting and managing intake invite tokens.
 *
 * Command names were shortened from ctt-patient-intake:* to headless-intake:* to
 * match the module. The old names remain as aliases, so any muscle memory or
 * documentation using them still works.
 */
final class IntakeInviteCommands extends DrushCommands {

  /**
   * Returns the invite service.
   *
   * @return \Drupal\headless_intake\IntakeInviteService
   *   The invite token service.
   */
  private function invites(): IntakeInviteService {
    return \Drupal::service('headless_intake.invite');
  }

  /**
   * Issues a new intake invite token for a clinic.
   *
   * The token is printed and must be handed to the patient as /intake/{token}.
   *
   * @param int $clinic_id
   *   ID of the clinic entity.
   * @param array<string, mixed> $options
   *   Command options: expires, max-uses.
   */
  #[CLI\Command(
    name: 'headless-intake:invite-issue',
    aliases: ['ctt-patient-intake:invite-issue', 'ctt-invite-issue'],
  )]
  #[CLI\Argument(name: 'clinic_id', description: 'ID of the clinic entity.')]
  #[CLI\Option(name: 'expires', description: 'Expiry as a strtotime-compatible value, e.g. "7 days".')]
  #[CLI\Option(name: 'max-uses', description: 'Number of successful submissions allowed (default 1).')]
  #[CLI\Usage(name: 'drush headless-intake:invite-issue 1', description: 'Mints a fresh one-time token for clinic 1.')]
  public function issue(
    int $clinic_id,
    array $options = [
      'expires' => NULL,
      'max-uses' => 1,
    ],
  ): void {
    $expires_at = NULL;
    if (!empty($options['expires'])) {
      $parsed = strtotime((string) $options['expires']);
      if ($parsed === FALSE) {
        throw new \InvalidArgumentException('--expires must be a valid date/time string.');
      }
      $expires_at = $parsed;
    }
    $max_uses = (int) ($options['max-uses'] ?? 1);
    $token = $this->invites()->issue($clinic_id, $expires_at, $max_uses);
    $this->io()->writeln($token);
    $this->io()->success("Intake invite issued for clinic $clinic_id (max-uses $max_uses).");
  }

  /**
   * Lists issued intake invite tokens, optionally for one clinic.
   *
   * @param int|null $clinic_id
   *   Optional clinic id to filter on.
   */
  #[CLI\Command(
    name: 'headless-intake:invite-list',
    aliases: ['ctt-patient-intake:invite-list', 'ctt-invite-list'],
  )]
  #[CLI\Argument(name: 'clinic_id', description: 'Optional clinic ID to filter on.')]
  public function listTokens(?int $clinic_id = NULL): void {
    $query = \Drupal::database()->select('headless_intake_invite', 'i')
      ->fields('i')
      ->orderBy('created', 'DESC')
      ->range(0, 100);
    if ($clinic_id !== NULL) {
      $query->condition('clinic_id', $clinic_id);
    }
    $rows = $query->execute()->fetchAllAssoc('token');
    if (!$rows) {
      $this->io()->writeln('No invite tokens issued.');
      return;
    }
    $table = [];
    foreach ($rows as $row) {
      $expires = $row->expires_at
        ? date('Y-m-d H:i', (int) $row->expires_at)
        : 'never';
      $table[] = [
        'token' => $row->token,
        'clinic' => $row->clinic_id,
        'status' => $row->status,
        'uses' => $row->uses . '/' . $row->max_uses,
        'expires' => $expires,
        'created' => date('Y-m-d H:i', (int) $row->created),
      ];
    }
    $this->io()->table(array_keys($table[0]), $table);
  }

  /**
   * Revokes an intake invite token.
   *
   * @param string $token
   *   Token value to revoke.
   */
  #[CLI\Command(
    name: 'headless-intake:invite-revoke',
    aliases: ['ctt-patient-intake:invite-revoke', 'ctt-invite-revoke'],
  )]
  #[CLI\Argument(name: 'token', description: 'Token value to revoke.')]
  public function revoke(string $token): void {
    if (!$this->invites()->revoke($token)) {
      $this->io()->error("No invite token found: $token");
      return;
    }
    $this->io()->success("Revoked invite token.");
  }

}
