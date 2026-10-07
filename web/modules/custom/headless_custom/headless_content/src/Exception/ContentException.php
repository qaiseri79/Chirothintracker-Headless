<?php

declare(strict_types=1);

namespace Drupal\headless_content\Exception;

/**
 * A failure that is the caller's problem, not the server's.
 *
 * The same shape as headless_patients' PatientsException, deliberately: one
 * exception type per module, carrying its own status code, so the controller has
 * exactly one catch and the services hold every rule about what is an error.
 */
class ContentException extends \RuntimeException {

  /**
   * Constructs a ContentException.
   *
   * @param int $status
   *   HTTP status to return. 404 for a bundle that is not in the allowlist or a
   *   node that does not exist or is not visible to this caller, 403 for a
   *   caller with no portal identity to scope by.
   */
    public function __construct(
      string $message,
      private readonly int $status = 404,
    ) {
      // The status is passed to the parent as the exception code as well as being
      // held in the property, so that getCode() agrees with getStatusCode().
      //
      // RuntimeException's third constructor argument is the *code*, and the
      // constructor only takes a message, leaving the code 0. That is invisible in
      // the controller, which reads getStatusCode() — and wrong for everything else
      // that inspects the exception the conventional way: a watchdog log entry built
      // with @code, a test using expectExceptionCode(), or any future middleware.
      // Passing it twice is not redundancy, it is making the standard accessor agree
      // with the module's own.
      parent::__construct($message, $status);
    }

  /**
   * The HTTP status this failure should be reported as.
   */
  public function getStatusCode(): int {
    return $this->status;
  }

  /**
   * The bundle named in the URL is not one this module serves.
   *
   * 404 rather than 400: the bundle is part of the path, so an unsupported one is
   * a missing resource as far as the caller is concerned.
   */
  public static function unknownBundle(string $bundle): self {
    return new self(sprintf('There is no "%s" content library.', $bundle));
  }

  /**
   * The node does not exist, is unpublished, or is not visible to this caller.
   *
   * One message for all three, on the same reasoning as
   * PatientsException::notFound(): an unpublished node and a node belonging to
   * another patient must be indistinguishable, or the endpoint becomes a probe
   * for what other patients are receiving.
   */
  public static function notFound(): self {
    return new self('No such content item.');
  }

  /**
   * The account has no clinic to scope content by.
   *
   * A signed-in portal member normally always has one — 8 accounts site-wide point
   * at no clinic and 16 at an id that no longer exists. This fires for anonymous,
   * unassigned or stale-reference accounts rather than being assumed impossible,
   * because the alternative reading of "no clinic" is "no scope at all", which for
   * the resource library means the whole library.
   */
  public static function noClinic(): self {
    return new self('This account has no clinic and cannot read the content library.', 403);
  }

  /**
   * The `favorites` flag does not apply to this bundle.
   *
   * 400 rather than 404: the node exists and is readable, the action simply does
   * not exist for it. Only `recipe` is flaggable today.
   */
  public static function notFavouriteable(string $bundle): self {
    return new self(sprintf('"%s" content cannot be favourited.', $bundle), 400);
  }

  /**
   * The caller may not flag or unflag, for want of `flag favorites`.
   *
   * Today only `enrolled_patient` holds it. Chiropractors and archived patients
   * are refused here exactly as the legacy ajax link refused them.
   *
   * The message says so in terms the user can act on, unlike {@see
   * self::notFavouriteable()} which names a bundle.
   */
  public static function favouriteNotAllowed(): self {
    return new self('This account cannot favourite content.', 403);
  }

  /**
   * The request body was not usable as a favourite instruction.
   *
   * 400, and deliberately reached only for a body that claims to carry `favourite`
   * but not in a boolean shape. A body with no `favourite` key at all is a toggle
   * request, not an error — see ContentController::wantedFavourite().
   *
   * The 400 is checked before the visibility of the node on purpose: a caller
   * posting nonsense to a node they cannot see learns nothing, because the message
   * is the same either way and reveals no bundle or id.
   */
  public static function invalidFavouriteBody(): self {
    return new self(
      'The request body must be JSON with a boolean "favourite" field, or empty to toggle.',
      400,
    );
  }

  /**
   * This bundle cannot be created through the API.
   *
   * 400 rather than 404: the bundle is a supported library, the action simply does
   * not exist for it. Only `recipe` has a create route today; training and
   * resources stay admin-authored, matching the read-only promise of §5.4 in the
   * headless_custom README.
   */
  public static function cannotCreate(string $bundle): self {
    return new self(sprintf('"%s" content cannot be created here.', $bundle), 400);
  }

  /**
   * The request body was not usable as a create instruction.
   *
   * 400, reached only for a body the controller cannot read as a JSON object at
   * all. A body that *is* an object but fails a field rule is
   * {@see self::invalidCreate()} instead, so "I could not read you" and "I read
   * you and you named no title" stay two different answers.
   */
  public static function invalidCreateBody(): self {
    return new self('The request body must be a JSON object.', 400);
  }

  /**
   * A create field failed validation.
   *
   * 400. One factory for every field rule, because the messages are the same
   * shape ("X is required", "X accepts at most 2 terms") and the caller shows
   * them in the form they came from.
   */
  public static function invalidCreate(string $message): self {
    return new self($message, 400);
  }

  /**
   * A paging parameter was present but was not a whole number.
   *
   * 400, and only for a value that is *present and unreadable*: `?page=abc`,
   * `?page=1.5`, `?page=0x10`. An absent parameter is not an error. A readable value
   * that is simply too small is {@see self::pagingOutOfRange()} instead — two different
   * mistakes, and {@see \Drupal\headless_content\ContentService::normalisePaging()} is
   * the one place that decides what a paging value means.
   *
   * The distinction matters because these parameters used to be unreadable. They were
   * declared as route defaults, and Symfony resolves controller arguments from request
   * *attributes*, which a query string does not populate — so `?page=3&page_size=200`
   * arrived as `page=1, page_size=50`. Every request returned the same first page, and
   * a client paging through the library got one page repeated. The failure was silent
   * in the worst way: each response was individually valid and reported a truthful
   * `pageCount`, so nothing anywhere looked wrong. Hence a named exception for the one
   * case where the value is unambiguously the caller's mistake.
   *
   * @param string $name
   *   The query parameter, `page` or `page_size`.
   * @param string $given
   *   What the caller sent.
   */
  public static function invalidPaging(string $name, string $given): self {
    return new self(
      sprintf('"%s" must be a whole number, got "%s".', $name, $given),
      400,
    );
  }

  /**
   * A paging parameter was readable, but its value is below the range paging has.
   *
   * 400, and deliberately a different answer from {@see self::invalidPaging()}: that
   * one means "I could not read it", this one means "I read it and it cannot mean
   * anything". `0` is the case that matters. Pages are 1-based, so `page=0` names no
   * page at all.
   *
   * This used to be a clamp to page one, and the clamp was the bug rather than the
   * fix. `listBundle()` turns the page into
   * `range(($page - 1) * $pageSize, $pageSize)`, so `page=0` is `range(-30, 30)`;
   * MySQL reads the negative offset as zero and returns page one. A client that
   * indexes pages from 0 and walks `0..pageCount` therefore receives page one twice
   * and concatenates it. Measured on this site at `page_size=30`, a 116-recipe clinic
   * reached the frontend as 146 rows: every response was individually valid,
   * `totalItems` was truthful at 116, `pageCount` was truthful at 4, and the only
   * symptom was duplicated recipes in the list.
   *
   * Clamping to page one destroyed the one signal that would have shown the client's
   * arithmetic was wrong — it answered a request that named no page with a page, so
   * the duplicate landed silently. A 400 names the problem in a single request.
   *
   * @param string $name
   *   The query parameter, `page` or `page_size`.
   * @param int $given
   *   What the caller sent.
   */
  public static function pagingOutOfRange(string $name, int $given): self {
    return new self(
      sprintf('"%s" must be 1 or greater, got %d.', $name, $given),
      400,
    );
  }

}
