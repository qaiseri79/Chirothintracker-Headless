<?php

namespace Drupal\xnumber\Utility;

use Drupal\Component\Utility\Number;

/**
 * Provides helper methods for manipulating numbers.
 *
 * @ingroup utility
 */
class Xnumber extends Number {

  /**
   * Verifies that a number is a multiple of a given step.
   *
   * The implementation assumes it is dealing with IEEE 754 double precision
   * floating point numbers that are used by PHP on most systems.
   *
   * This is based on the number/range verification methods of webkit.
   *
   * @param float $value
   *   The value that needs to be checked.
   * @param float $step
   *   The step scale factor. Must be positive.
   * @param float $min
   *   (optional) A minimum, to which the difference must be a multiple of the
   *   given step.
   *
   * @return bool
   *   TRUE if no step mismatch has occurred, or FALSE otherwise.
   *
   * @see http://opensource.apple.com/source/WebCore/WebCore-1298/html/NumberInputType.cpp
   * @see https://en.wikipedia.org/wiki/Machine_epsilon
   */
  public static function validStep($value, $step, $min = NULL) {
    $scale = static::getDecimalDigits($step);
    // Set scale for all subsequent BC MATH calculations.
    bcscale($scale);
    $value = static::toString($value, $scale);
    $step = static::toString($step, $scale);

    // The $step must be greater than 0.
    if (bccomp($step, '0') !== 1) {
      return FALSE;
    }

    $comp = bccomp($value, $step);

    if (is_numeric($min)) {
      $floor = static::toString($min, $scale);
      $ceil = $value;

      // No further checking has sense if $min is greater than $value.
      if (bccomp($floor, $value) === 1) {
        return FALSE;
      }
    }
    elseif ($comp === 1) {
      $floor = $step;
      $ceil = $value;
    }
    else {
      $floor = $value;
      $ceil = $step;
    }

    // Both $value and $min are equal so any $step is valid.
    if (bccomp($value, $floor) === 0) {
      return TRUE;
    }

    // The $step is valid if it is equal to the unsigned $value.
    if (bccomp(static::toString(abs($value), $scale), $step) === 0) {
      return TRUE;
    }

    // The difference between $min and $step must be equal or more than $value.
    if (bccomp($value, $step) < 0 && bccomp(bcadd($floor, $step), $value) > 0) {
      return FALSE;
    }

    // If the $scale is 0 then we have an integer $step.
    if ($scale === 0 && static::getDecimalDigits($value) == 0) {
      // Quite robust solution for any range between $ceil and $floor.
      // @see http://php.net/manual/en/function.bcmod.php#38474
      $sub = bcsub($ceil, $floor);
      $remainder = '';

      do {
        $substr = (int) $remainder . substr($sub, 0, 5);
        $sub = substr($sub, 5);
        $remainder = bcmod($substr, $step);
      } while (strlen($sub));

      if (bccomp(static::toString($remainder, $scale), '0') === 0) {
        return TRUE;
      }
    }
    else {
      $floor = bcsub($floor, $step);
      $val = bcadd($value, $step);
      $remainder = bcmod(bcsub($val, $floor), $step);
      if (bccomp($remainder, '0') === 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Helper method to truncate a decimal number to a given number of decimals.
   *
   * @param float $decimal
   *   Decimal number to truncate.
   * @param int $digits
   *   Number of digits the output will have.
   *
   * @return float
   *   Decimal number truncated.
   */
  public static function truncateDecimal($decimal, $digits = 0) {
    return floor($decimal * pow(10, $digits)) / pow(10, $digits);
  }

  /**
   * Wrapper for the PHP number_format().
   *
   * @param float|int|string $number
   *   The integer or decimal or floating point number to format.
   * @param int $scale
   *   The number of digits after a decimal point.
   *
   * @return string|void
   *   The string representation of a number.
   */
  public static function numberFormat($number, $scale = -1) {
    if (is_numeric($number)) {
      $number = number_format((double) $number, $scale > -1 ? $scale : 10, '.', '');
      return rtrim(rtrim($number, '0'), '.');
    }
  }

  /**
   * Helper method to detect whether the number has reasonable length or size.
   *
   * @param float|string|int $number
   *   The number to test.
   * @param int $length
   *   The safe length.
   *
   * @return bool|void
   *   Whether the length is safe.
   */
  public static function isNumberSafe($number, $length = 14) {
    if (is_numeric($number)) {
      if (is_float($number) || strstr($number, '.') !== FALSE) {
        $number = str_replace(['-', '.'], '', static::numberFormat($number));
        return strlen($number) <= $length;
      }
      // The biggest supported integer number.
      $big = static::getStorageMaxMin('big')['signed'];
      return $number <= $big['max'] && $number >= $big['min'];
    }
  }

  /**
   * Helper method to get the number of decimal digits out of a number.
   *
   * @param float|int|string $number
   *   The number to calculate the number of decimals digits from.
   *
   * @return int|void
   *   The number of decimal digits.
   */
  public static function getDecimalDigits($number) {
    if (is_numeric($number)) {
      if (stristr($number, 'e')) {
        $number = static::toString($number);
      }
      $parts = explode('.', $number);
      if (isset($parts[1])) {
        return strlen($parts[1]);
      }
      return 0;
    }
  }

  /**
   * Helper method to cast a number to string.
   *
   * @param float|int|string $number
   *   The integer or decimal or floating point number to cast.
   * @param int $scale
   *   The number of digits after a decimal point.
   *
   * @return string|void
   *   The string representation of a number.
   */
  public static function toString($number, $scale = -1) {
    if (is_numeric($number)) {
      $safe = static::isNumberSafe($number);
      // Ensure number is not in scientific notation.
      if (stristr($number, 'e')) {
        if (!$safe) {
          // In this case the decimal part might be half up rounded.
          return static::numberFormat($number, $scale);
        }
        $minus = $number < 0 ? '-' : '';
        $abs = abs(floatval($number));
        $float = explode('.', ($abs + 1));
        $number = $minus . ($float[0] - 1);
        if (!empty($float[1])) {
          return $number . '.' . $float[1];
        }
      }
      if (!$safe) {
        return static::numberFormat($number, $scale);
      }

      return (string) $number;
    }
  }

  /**
   * Helper method to get number min and max storage sizes.
   *
   * @param string|array $size
   *   (optional) The storage size name or an array with precision and scale.
   *
   * @return array
   *   An array of sizes keyed by a size name.
   *
   * @see \Drupal\Core\Database\Driver\mysql::getFieldTypeMap()
   * @see \Drupal\Core\Database\Driver\pgsql::getFieldTypeMap()
   * @see \Drupal\Core\Database\Driver\sqlite::getFieldTypeMap()
   * @see https://dev.mysql.com/doc/refman/5.7/en/integer-types.html
   * @see https://dev.mysql.com/doc/refman/5.7/en/fixed-point-types.html
   * @see https://mariadb.com/kb/en/mariadb/data-types-numeric-data-types/
   * @see https://www.postgresql.org/docs/9.5/static/datatype-numeric.html
   * @see https://www.sqlite.org/datatype3.html
   */
  public static function getStorageMaxMin($size = NULL) {
    $sizes = [
      'tiny' => [
        'signed' => [
          'min' => '-128',
          'max' => '127',
        ],
        'unsigned' => '255',
      ],
      'small' => [
        'signed' => [
          'min' => '-32768',
          'max' => '32767',
        ],
        'unsigned' => '65535',
      ],
      'medium' => [
        'signed' => [
          'min' => '-8388608',
          'max' => '8388607',
        ],
        'unsigned' => '16777215',
      ],
      'normal' => [
        'signed' => [
          'min' => '-2147483648',
          'max' => '2147483647',
        ],
        'unsigned' => '4294967295',
      ],
      'big' => [
        'signed' => [
          'min' => '-9223372036854775808',
          'max' => '9223372036854775807',
        ],
        // @todo Find out why it fails even with options arg to filter_var() in:
        // @see Drupal\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraintValidator::validate()
        // 'unsigned' => '18446744073709551615'.
        'unsigned' => '9223372036854775807',
      ],
    ];

    if (isset($size['precision']) && isset($size['scale'])) {
      $integers = str_pad('', ($size['precision'] - $size['scale']), '9');
      $decimals = str_pad('', $size['scale'], '9');
      $max = ($integers ? $integers : '0') . ($decimals ? ".$decimals" : '');
      $sizes = [
        'signed' => [
          'min' => "-$max",
          'max' => $max,
        ],
        'unsigned' => $max,
      ];
    }
    elseif (isset($sizes[$size])) {
      $sizes = $sizes[$size];
    }

    return $sizes;
  }

}
