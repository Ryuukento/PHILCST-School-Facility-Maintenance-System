<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DispatchController;
use App\Services\PersonnelDirectoryService;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * TASK 65 GATE A — Dispatch must not depend on the legacy Repair service.
 *
 * Task 42 recorded DispatchController's dependency on RepairService as a Hard
 * Stop for retiring Repair Request: the Dispatch release-personnel selector
 * called RepairService::searchTechnicians() purely to run a generic "active
 * user with an allowed role" query, which contains no repair-domain logic at
 * all. Deleting RepairService would therefore have silently broken the Dispatch
 * personnel picker — a cross-feature breakage that is easy to miss because no
 * Dispatch test names RepairService.
 *
 * Task 65 extracted that query into PersonnelDirectoryService. These tests lock
 * the decoupling in place so it cannot silently regress: the behavioural
 * guarantees are already covered by DispatchReleaseAssignmentAuthorizationTest
 * (department scoping, session-only department, active-staff-only), so what is
 * asserted here is the STRUCTURAL property those tests cannot see.
 *
 * This is deliberately a structural assertion rather than a behavioural one.
 * Re-introducing the coupling would not fail any behavioural test — the
 * endpoint would keep returning the right users — which is precisely why the
 * regression needs its own guard.
 *
 * TASK 13 (Repair retirement) — THIS FILE IS DELIBERATELY NOT DELETED. The
 * retirement brief allows removing it only "if it becomes entirely obsolete",
 * and it has not: the Hard Stop this test was written to guard is the exact
 * thing Task 13 then went and did, so the first two tests below are now the
 * proof that the deletion was safe rather than merely preparation for it.
 * They also satisfy the required regression proof that no shared service was
 * accidentally deleted — PersonnelDirectoryService is on the brief's
 * protected list and both tests reflect over it directly.
 *
 * `use App\Services\RepairService;` was dropped from the imports because the
 * class no longer exists. The first test still names it as a forbidden
 * dependency via its fully-qualified string: a `use` statement does not
 * autoload, and the assertion only ever compared class-name strings, so the
 * guard is unchanged in strength — it would still fail if someone recreated
 * RepairService and re-injected it.
 */
class DispatchRepairServiceDecouplingTest extends TestCase
{
    /**
     * The constructor is the coupling point. If RepairService reappears here,
     * retiring Repair Request breaks the Dispatch personnel picker again.
     */
    public function test_dispatch_controller_does_not_depend_on_repair_service(): void
    {
        $constructor = (new ReflectionClass(DispatchController::class))->getConstructor();

        $this->assertNotNull($constructor, 'DispatchController is expected to use constructor injection.');

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $type->getName();
            }
        }

        // Fully-qualified string rather than ::class — see the class docblock.
        // RepairService was deleted in Task 13, so there is no class constant
        // to reference, but the guard must still name it so that recreating
        // and re-injecting it fails here.
        $this->assertNotContains(
            'App\Services\RepairService',
            $dependencies,
            'DispatchController must not depend on the legacy RepairService. '
            . 'The generic personnel lookup lives in PersonnelDirectoryService.'
        );

        $this->assertContains(
            PersonnelDirectoryService::class,
            $dependencies,
            'DispatchController should obtain personnel from PersonnelDirectoryService.'
        );
    }

    /**
     * The extracted service must stay neutral. If repair-domain dependencies
     * leak back into it, the coupling has simply moved rather than been
     * removed.
     */
    public function test_personnel_directory_service_has_no_repair_domain_dependencies(): void
    {
        $constructor = (new ReflectionClass(PersonnelDirectoryService::class))->getConstructor();

        // No constructor at all is the strongest form of "no dependencies".
        if ($constructor === null) {
            $this->assertTrue(true);

            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $this->assertStringNotContainsString(
                'Repair',
                $type->getName(),
                'PersonnelDirectoryService must not take on repair-domain dependencies.'
            );
            $this->assertStringNotContainsString(
                'Damage',
                $type->getName(),
                'PersonnelDirectoryService must not take on damage-domain dependencies.'
            );
        }
    }

    /**
     * TASK 13 — this test was INVERTED, not deleted.
     *
     * It previously asserted the opposite: that
     * RepairService::searchTechnicians() was KEPT as a delegating wrapper,
     * because Task 65 was preparation only and removing legacy functionality
     * was out of scope then. Task 13 IS that removal, so the old assertion is
     * now factually wrong and would fail.
     *
     * Inverting rather than dropping it keeps the retirement PINNED. If the
     * assertion were simply deleted, nothing would stop RepairService being
     * reintroduced; as written, the file that once guarded the wrapper's
     * existence now guards its absence, and the surrounding decoupling
     * coverage in this class is untouched.
     *
     * class_exists() is used rather than method_exists() so the failure
     * message distinguishes "the whole service came back" from "the service
     * is gone" — method_exists() returns false for a missing class too, which
     * would make a resurrected RepairService without that one method pass.
     */
    public function test_the_repair_service_is_retired(): void
    {
        $this->assertFalse(
            class_exists(\App\Services\RepairService::class),
            'App\Services\RepairService was deleted in Task 13 (application-level '
            . 'Repair Request retirement) and must not be reintroduced. Its only '
            . 'non-Repair responsibility, the generic personnel lookup, lives in '
            . 'PersonnelDirectoryService — which the tests above prove is intact.'
        );

        $this->assertFalse(
            class_exists(\App\Http\Controllers\Api\RepairController::class),
            'App\Http\Controllers\Api\RepairController was deleted in Task 13 '
            . 'and must not be reintroduced.'
        );
    }
}
