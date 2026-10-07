<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\headless_access\PortalRoles;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContentCapabilities.
 *
 * The frontend gates the favourite star on `canFavourite` and the "Add recipe"
 * button on `canCreate`, so getting this matrix wrong either shows a patient a
 * button whose endpoint returns 403 or 400, or hides the button from the one role
 * that can use it.
 *
 * @group headless_content
 * @coversDefaultClass \Drupal\headless_content\ContentCapabilities
 */
class ContentCapabilitiesTest extends TestCase {

  protected ContentCapabilities $capabilities;

  protected function setUp(): void {
    parent::setUp();

    $this->capabilities = new ContentCapabilities();
  }

  /**
   * Only an active chiropractor may create, and only on the recipe bundle.
   *
   * `headless_content.create` serves the recipe bundle gated by
   * `WriteChiropractorAccess`, which names `chiropractor_active_` alone. The
   * capability keyed to the route reports the same: TRUE for exactly that caller
   * and bundle, FALSE everywhere else — including the inactive chiropractor, who
   * stays read-only everywhere, and the enrolled patient, whose favourite grant
   * must not be read as a wider one.
   */
  public function testOnlyActiveChiropractorCanCreateOnRecipe(): void {
    $this->assertTrue($this->capabilities->forRoles([PortalRoles::CHIROPRACTOR_ACTIVE], 'recipe')['canCreate']);

    $this->assertFalse($this->capabilities->forRoles([PortalRoles::CHIROPRACTOR_INACTIVE], 'recipe')['canCreate'], 'an inactive chiropractor must not be told it can create');
    $this->assertFalse($this->capabilities->forRoles([PortalRoles::PATIENT_ENROLLED], 'recipe')['canCreate']);
    $this->assertFalse($this->capabilities->forRoles([PortalRoles::PATIENT_ARCHIVED], 'recipe')['canCreate']);
    $this->assertFalse($this->capabilities->forRoles([PortalRoles::ADMINISTRATOR], 'recipe')['canCreate']);
  }

  /**
   * `canCreate` is bundle-dependent, like `canFavourite`.
   *
   * The create route is pinned to `recipe|training|chirothin_resource` and
   * `ContentService::create()` refuses every other bundle. Reporting TRUE for a
   * bundle with no create route would put an "Add training" button on a library
   * the endpoint would 400 — the flag and the route must not disagree about the
   * bundle.
   */
  public function testCreateIsBundleDependent(): void {
    $roles = [PortalRoles::CHIROPRACTOR_ACTIVE];

    $this->assertTrue($this->capabilities->forRoles($roles, 'recipe')['canCreate']);
    $this->assertTrue($this->capabilities->forRoles($roles, 'training')['canCreate']);
    $this->assertTrue($this->capabilities->forRoles($roles, 'chirothin_resource')['canCreate']);
    $this->assertFalse($this->capabilities->forRoles($roles, 'some_other_bundle')['canCreate']);
  }

  /**
   * `canCreate` is still false for every other role on every creatable bundle.
   */
  public function testOnlyActiveChiropractorCanCreateOnTraining(): void {
    $this->assertTrue($this->capabilities->forRoles([PortalRoles::CHIROPRACTOR_ACTIVE], 'training')['canCreate']);

    $this->assertFalse($this->capabilities->forRoles([PortalRoles::CHIROPRACTOR_INACTIVE], 'training')['canCreate'], 'an inactive chiropractor must not be told it can create');
    $this->assertFalse($this->capabilities->forRoles([PortalRoles::PATIENT_ENROLLED], 'training')['canCreate']);
    $this->assertFalse($this->capabilities->forRoles([PortalRoles::PATIENT_ARCHIVED], 'training')['canCreate']);
    $this->assertFalse($this->capabilities->forRoles([PortalRoles::ADMINISTRATOR], 'training')['canCreate']);
  }

  /**
   * No role may delete or edit through this API.
   *
   * This is the assertion that changed most recently, and the direction it changed
   * is the point. The previous matrix reported `canCreate: true` for
   * `administrator`, describing the Drupal-side permission rather than anything this
   * module exposes. There was no create route, so a frontend honouring that flag
   * rendered a button pointing at nothing. Administrators author content in
   * `/admin`, which is a different surface from `/api/headless` — and there is still
   * no delete or edit route for anyone.
   */
  public function testNoRoleCanDeleteOrEdit(): void {
    foreach ($this->everyRole() as $label => $roles) {
      $caps = $this->capabilities->forRoles($roles, 'recipe');

      $this->assertFalse($caps['canDelete'], "$label must not be told it can delete");
      $this->assertFalse($caps['canManageOwnContent'], "$label must not be told it can edit");
    }
  }

  /**
   * Administrator is described for the frontend, but is never granted anything.
   *
   * The label is kept because a module that reports a capability block for a role it
   * does not serve should say which role it saw. The grants are not, per the test
   * above.
   */
  public function testAdministratorIsLabelledAndUngranted(): void {
    $caps = $this->capabilities->forRoles([PortalRoles::ADMINISTRATOR], 'recipe');

    $this->assertSame('administrator', $caps['audience']);
    $this->assertFalse($caps['canFavourite']);
    $this->assertFalse($caps['canCreate']);
  }

  /**
   * Only the enrolled patient may favourite.
   *
   * Straight from `config/sync/user.role.*`: `flag favorites` and `unflag favorites`
   * appear on `enrolled_patient` and nowhere else. `chiropractor_active_` holds
   * eleven other flags and not this one, which is exactly the kind of detail that
   * gets assumed rather than read.
   */
  public function testOnlyEnrolledPatientCanFavourite(): void {
    $this->assertTrue($this->capabilities->forRoles([PortalRoles::PATIENT_ENROLLED], 'recipe')['canFavourite']);

    $this->assertFalse($this->capabilities->forRoles([PortalRoles::CHIROPRACTOR_ACTIVE], 'recipe')['canFavourite']);
    $this->assertFalse($this->capabilities->forRoles([PortalRoles::CHIROPRACTOR_INACTIVE], 'recipe')['canFavourite']);
    $this->assertFalse($this->capabilities->forRoles([PortalRoles::PATIENT_ARCHIVED], 'recipe')['canFavourite']);
  }

  /**
   * A chiropractor gets no star even on the bundle where favourites work.
   *
   * Stated separately from the test above because this is the one that regresses if
   * someone reads "patients can favourite" as "everyone who is not a chiropractor",
   * and then adds the inactive chiropractor for symmetry. The inactive role is
   * read-only *everywhere* in the portal, and a star is a write.
   */
  public function testInactiveChiropractorCannotFavourite(): void {
    $caps = $this->capabilities->forRoles([PortalRoles::CHIROPRACTOR_INACTIVE], 'recipe');

    $this->assertFalse($caps['canFavourite']);
    $this->assertSame('chiropractor', $caps['audience']);
  }

  /**
   * Holding `flag favorites` is not enough for a bundle the flag does not cover.
   *
   * `flag.flag.favorites.yml` has `bundles: [recipe]`. An enrolled patient who
   * favourites a training video would get a 400 from ContentFavorites::set(), so
   * reporting `canFavourite: true` for `training` would put a star on the list that
   * cannot be pressed.
   */
  public function testFavouriteIsBundleDependent(): void {
    $roles = [PortalRoles::PATIENT_ENROLLED];

    $this->assertTrue($this->capabilities->forRoles($roles, 'recipe')['canFavourite']);
    $this->assertFalse($this->capabilities->forRoles($roles, 'training')['canFavourite']);
    $this->assertFalse($this->capabilities->forRoles($roles, 'chirothin_resource')['canFavourite']);
  }

  /**
   * An unknown bundle cannot be favourited.
   *
   * `forRoles()` is called with the path's bundle, which routing already constrains
   * to the three known values. A fourth one would be a 404 from ContentService, but
   * the capability block must not be the thing that decides a bundle is favouritable
   * by omission.
   */
  public function testUnknownBundleCannotBeFavourited(): void {
    $this->assertFalse(
      $this->capabilities->forRoles([PortalRoles::PATIENT_ENROLLED], 'some_other_bundle')['canFavourite']
    );
  }

  /**
   * An account holding both a chiropractor and a patient role is a chiropractor.
   *
   * The chiropractor role is the one with the grant, and `audience` describes what
   * the UI is being shown rather than what the account technically holds. Note the
   * favourite grant is a straight role-membership test, so such an account can
   * favourite recipes — which matches what Drupal's permission system does, since it
   * is also a straight membership test there.
   */
  public function testChiropractorRoleWinsForAudienceLabel(): void {
    $caps = $this->capabilities->forRoles([
      PortalRoles::PATIENT_ENROLLED,
      PortalRoles::CHIROPRACTOR_ACTIVE,
    ], 'recipe');

    $this->assertSame('chiropractor', $caps['audience']);
    $this->assertTrue($caps['canFavourite']);
    $this->assertTrue($caps['canCreate']);
  }

  /**
   * The audience label for each portal role.
   *
   * Pinned because the frontend branches on it and a fallback here would render the
   * patient's UI for a chiropractor.
   */
  public function testAudienceLabels(): void {
    $this->assertSame('chiropractor', $this->label([PortalRoles::CHIROPRACTOR_ACTIVE]));
    $this->assertSame('chiropractor', $this->label([PortalRoles::CHIROPRACTOR_INACTIVE]));
    $this->assertSame('patient', $this->label([PortalRoles::PATIENT_ENROLLED]));
    $this->assertSame('archived_patient', $this->label([PortalRoles::PATIENT_ARCHIVED]));
    $this->assertSame('administrator', $this->label([PortalRoles::ADMINISTRATOR]));
  }

  /**
   * An account with no portal role gets no grant and an explicit label.
   *
   * `unknown` rather than a fallback to 'patient': the role gate
   * (PortalMemberAccess) already refuses this account at the route, so the label
   * is a diagnostic for a misconfigured route rather than a real case.
   */
  public function testNoPortalRoleIsUnknownAndUngranted(): void {
    $caps = $this->capabilities->forRoles(['authenticated'], 'recipe');

    $this->assertSame('unknown', $caps['audience']);
    $this->assertFalse($caps['canFavourite']);
    $this->assertFalse($caps['canCreate']);
    $this->assertFalse($caps['canDelete']);
  }

  /**
   * The capability keys are stable.
   *
   * The frontend reads these by name, so a rename or a drop is a breaking API change
   * even though every value is correct. Asserted as a shape rather than as five
   * separate assertions.
   */
  public function testCapabilityKeysAreStable(): void {
    $caps = $this->capabilities->forRoles([PortalRoles::PATIENT_ENROLLED], 'recipe');

    $this->assertSame(
      ['canCreate', 'canDelete', 'canManageOwnContent', 'canFavourite', 'audience'],
      array_keys($caps),
    );
    $this->assertContainsOnly('bool', [$caps['canCreate'], $caps['canDelete'], $caps['canManageOwnContent'], $caps['canFavourite']]);
    $this->assertIsString($caps['audience']);
  }

  /**
   * Every role with its label, for the loops above.
   *
   * @return array<string, array<int, string>>
   */
  private function everyRole(): array {
    return [
      'chiropractor_active_' => [PortalRoles::CHIROPRACTOR_ACTIVE],
      'chiropractor_inactive_' => [PortalRoles::CHIROPRACTOR_INACTIVE],
      'enrolled_patient' => [PortalRoles::PATIENT_ENROLLED],
      'archived_patient' => [PortalRoles::PATIENT_ARCHIVED],
      'administrator' => [PortalRoles::ADMINISTRATOR],
    ];
  }

  /**
   * The audience label for a role list.
   *
   * @param array<int, string> $roles
   */
  private function label(array $roles): string {
    return $this->capabilities->forRoles($roles, 'recipe')['audience'];
  }

}