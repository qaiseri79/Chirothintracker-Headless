<?php

declare(strict_types=1);

namespace Drupal\headless_patients;

/**
 * Translates between the stored program phase and the roster's phase code.
 *
 * A user's phase is stored as an opaque string key on `field_weight_loss_phase`,
 * and the meaningful labels live in hardcoded arrays in custom_module:
 * SetLockField.php renders one, CustomRoute.php writes the other. Those arrays
 * are the authority, and they are not in the same order as the roster's own
 * codes, which is exactly the kind of thing that silently drifts.
 *
 * The mapping, from SetLockField.php's labels:
 *
 *   phase-0  Zero-Day (Pre-Loading)     Z
 *   phase-1  Loading Phase              D
 *   phase-2  Losing Phase               L
 *   phase-3  Cycling / Maintenance      M
 *   phase-4  Continuity Phase           C
 *
 * The codes are not the phase number: C is phase-4 and M is phase-3. Anyone
 * adding a phase has to come through this class, and cannot get the codes to
 * match the numbering by accident because the two are stored separately.
 *
 * Order is the order the Add-patient form lists its options in, so `options()`
 * can be used to build a select directly.
 */
class PatientPhaseMap {

  /**
   * Code => stored value and label, in the order the form lists them.
   */
  private const PHASES = [
    'L' => ['stored' => 'phase-2', 'label' => 'Losing Phase'],
    'D' => ['stored' => 'phase-1', 'label' => 'Loading Phase'],
    'Z' => ['stored' => 'phase-0', 'label' => 'Zero-Day (Pre-Loading)'],
    'C' => ['stored' => 'phase-4', 'label' => 'Continuity Phase'],
    'M' => ['stored' => 'phase-3', 'label' => 'Cycling / Maintenance Phase'],
  ];

  /**
   * The stored value for each code, keyed by code.
   *
   * @return array<string, string>
   */
  public function storedByCode(): array {
    return array_map(
      static fn (array $phase): string => $phase['stored'],
      self::PHASES,
    );
  }

  /**
   * The code for each stored value, keyed by stored value.
   *
   * @return array<string, string>
   */
  public function codeByStored(): array {
    $map = [];
    foreach (self::PHASES as $code => $phase) {
      $map[$phase['stored']] = $code;
    }
    return $map;
  }

  /**
   * The label for each code, in form order, ready to build a select.
   *
   * @return array<string, string>
   */
  public function options(): array {
    $options = [];
    foreach (self::PHASES as $code => $phase) {
      $options[$code] = $phase['label'];
    }
    return $options;
  }

  /**
   * Every code with its stored value and label, in form order.
   *
   * @return array<int, array{code: string, stored: string, label: string}>
   */
  public function all(): array {
    $all = [];
    foreach (self::PHASES as $code => $phase) {
      $all[] = [
        'code' => $code,
        'stored' => $phase['stored'],
        'label' => $phase['label'],
      ];
    }
    return $all;
  }

  /**
   * The code for a stored value, or NULL when it is not a known phase.
   */
  public function code(?string $stored): ?string {
    if ($stored === NULL || $stored === '') {
      return NULL;
    }
    return $this->codeByStored()[$stored] ?? NULL;
  }

  /**
   * The stored value for a code, or NULL when it is not a known code.
   */
  public function stored(?string $code): ?string {
    if ($code === NULL || $code === '') {
      return NULL;
    }
    return $this->storedByCode()[$code] ?? NULL;
  }

  /**
   * The label for a code, or NULL when it is not a known code.
   */
  public function label(?string $code): ?string {
    if ($code === NULL || !isset(self::PHASES[$code])) {
      return NULL;
    }
    return self::PHASES[$code]['label'];
  }

  /**
   * Whether a submitted value is a code this map knows.
   */
  public function isValidCode(?string $code): bool {
    return $code !== NULL && isset(self::PHASES[$code]);
  }
}
