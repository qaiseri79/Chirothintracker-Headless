<?php

declare(strict_types=1);

namespace Drupal\headless_access\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Base for the per-policy access callbacks.
 *
 * A route opts in with one requirement:
 *
 * @code
 * requirements:
 *   _custom_access: 'Drupal\headless_access\Access\ReadChiropractorAccess::access'
 * @endcode
 *
 * which Drupal resolves through `CustomAccessCheck`, which hands the callable to
 * `AccessArgumentsResolverFactory`. That factory seeds the arguments resolver with
 * three wildcards — the matched route, the route match, and the account — and the
 * resolver then matches each of this method's parameters against them *by type*,
 * so the parameter names here are documentation and the types are the contract.
 *
 * ## The Route type is Symfony's, and it has to be
 *
 * `Route` means `Symfony\Component\Routing\Route`, because that is the class
 * `$route_match->getRouteObject()` returns and therefore the class sitting in the
 * wildcards. There is no `Drupal\Core\Routing\Route`: `core/lib/Drupal/Core/Routing/`
 * holds RouteMatch, RouteProvider, RouteBuilder and the rest, but no plain `Route`.
 * Typing it as the Drupal one looks plausible and fails at request time, not at
 * compile time — `ArgumentsResolver::getArgument()` runs `new \ReflectionClass()` on
 * whatever the type hint names, so an unresolvable name throws "Class does not
 * exist" from inside the access check. The whole site then 500s on every route this
 * policy is attached to, with a stack trace that points at core rather than here.
 *
 * So: the type hint must name a class that exists, and it must be one of the three
 * wildcard types (`Route`, `RouteMatchInterface`, `AccountInterface`, `Request`) or
 * the parameter will not be supplied and the resolver throws on an unresolved
 * argument instead. Neither failure is visible until a request arrives.
 *
 * Each policy is its own class rather than one class taking a policy name so that a
 * route names the policy it wants and nothing has to be looked up at runtime. Three
 * small classes are cheaper than one class that has to be understood before any
 * route using it can be read.
 *
 * Subclasses declare {@see static::allowedRoles()} and nothing else. The decision
 * itself is a role membership test, and it is written once here so the policies
 * cannot disagree about how a role is checked.
 */
abstract class PortalRoleAccess {

  /**
   * The role machine names this policy admits.
   *
   * @return string[]
   *   Role IDs from {@see \Drupal\headless_access\PortalRoles}.
   */
  abstract protected static function allowedRoles(): array;

  /**
   * Who this policy is for, used in the forbidden message.
   *
   * @return string
   *   A short noun phrase, not a sentence.
   */
  abstract protected static function policySubject(): string;

  /**
   * Checks the account against this policy.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The matched route. Received by type-matching against the resolver's
   *   wildcards; not used by this policy, but part of the documented signature for
   *   an access check and available to a subclass that needs the path or a
   *   requirement.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account making the request. This is what the decision is made on.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Whether the account holds one of the allowed roles.
   */
  public static function access(Route $route, AccountInterface $account, ?\Symfony\Component\HttpFoundation\Request $request = NULL): AccessResultInterface {
    if (!$account->isAuthenticated() || !array_intersect($account->getRoles(), static::allowedRoles())) {
      return AccessResult::forbidden(sprintf('This endpoint is for %s accounts.', static::policySubject()))
        ->addCacheContexts(['user.roles']);
    }
    $access = \Drupal::service('headless_access.portal_access')->resolve($account);
    $doctorPolicy = static::allowedRoles() === \Drupal\headless_access\PortalRoles::CHIROPRACTOR_READ
      || static::allowedRoles() === \Drupal\headless_access\PortalRoles::CHIROPRACTOR_WRITE;
    $patientPolicy = static::allowedRoles() === [\Drupal\headless_access\PortalRoles::PATIENT_ENROLLED];
    $write = $patientPolicy
      || static::allowedRoles() === \Drupal\headless_access\PortalRoles::CHIROPRACTOR_WRITE
      || ($request && !$request->isMethodSafe());
    $allowed = $access && $access[$write ? 'write' : 'read']
      && (!$doctorPolicy || $access['audience'] === 'chiropractor')
      && (!$patientPolicy || $access['audience'] === 'patient');
    return AccessResult::allowedIf((bool) $allowed)->addCacheContexts(['user'])->setCacheMaxAge(0);
  }

}
