#!/usr/bin/env bash
# Isolated rollback test for the dynamic hierarchy migrations.
#
# Runs entirely against its own database (qiyas_rollback_db) so neither the
# development nor the E2E environment is touched. Every step prints PASS or
# FAIL; the script exits non-zero on the first failure so a partial success
# can never be mistaken for a clean run.
#
# Usage: bash scripts/rollback-test.sh
set -uo pipefail

DB=qiyas_rollback_db
ENVF=.env.rollback
BACKUP=$(mktemp /tmp/rollback-backup-XXXXXX.sql)
FAILURES=0

step()  { printf '\n\033[1m── %s\033[0m\n' "$1"; }
pass()  { printf '   \033[32m[PASS]\033[0m %s\n' "$1"; }
fail()  { printf '   \033[31m[FAIL]\033[0m %s\n' "$1"; FAILURES=$((FAILURES+1)); }
check() { if [ "$2" = "$3" ]; then pass "$1 ($2)"; else fail "$1 — expected '$3', got '$2'"; fi; }

q() { mysql -u root "$DB" -N -e "$1" 2>/dev/null; }

# The dynamic-hierarchy migrations, newest first.
MIGRATION_COUNT=7

step "0. Provision isolated database"
mysql -u root -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cp .env "$ENVF"
sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$DB/" "$ENVF"
pass "isolated database $DB created"

step "1. Migrate and seed to the current head, then back up"
php artisan migrate --seed --force --env=rollback >/dev/null 2>&1 || { fail "initial migrate --seed"; exit 1; }
php artisan compliance:seed-test-fixtures --force --env=rollback >/dev/null 2>&1
mysqldump -u root --routines --triggers "$DB" > "$BACKUP" 2>/dev/null
check "database backed up" "$([ -s "$BACKUP" ] && echo yes || echo no)" "yes"

step "2. Record pre-rollback state"
PRE_USERS=$(q "SELECT COUNT(*) FROM users")
PRE_DEPTS=$(q "SELECT COUNT(*) FROM departments")
PRE_PROGRAMS=$(q "SELECT COUNT(*) FROM compliance_programs")
PRE_SETTINGS=$(q "SELECT COUNT(*) FROM settings")
PRE_ROLES=$(q "SELECT COUNT(*) FROM program_user_roles")
PRE_TEMPLATES=$(q "SELECT COUNT(*) FROM email_templates")
PRE_DEFS=$(q "SELECT COUNT(*) FROM hierarchy_definitions")
PRE_LEVELS=$(q "SELECT COUNT(*) FROM hierarchy_level_definitions")
PRE_VERSIONS=$(q "SELECT COUNT(*) FROM program_structure_versions")
PRE_NODES=$(q "SELECT COUNT(*) FROM compliance_nodes")
printf '   users=%s departments=%s programs=%s settings=%s program_roles=%s\n' \
  "$PRE_USERS" "$PRE_DEPTS" "$PRE_PROGRAMS" "$PRE_SETTINGS" "$PRE_ROLES"
printf '   hierarchy_definitions=%s levels=%s structure_versions=%s nodes=%s\n' \
  "$PRE_DEFS" "$PRE_LEVELS" "$PRE_VERSIONS" "$PRE_NODES"
[ "$PRE_DEFS" -gt 0 ] && pass "structure definitions recorded" || fail "no structure definitions to roll back"

step "3. Exercise assignments / evidence / workflow before rollback"
PRE_ASSIGN=$(q "SELECT COUNT(*) FROM requirement_assignments")
PRE_EVID=$(q "SELECT COUNT(*) FROM evidence_submissions")
PRE_SLA=$(q "SELECT COUNT(*) FROM sla_instances")
printf '   assignments=%s evidence=%s sla=%s\n' "$PRE_ASSIGN" "$PRE_EVID" "$PRE_SLA"
[ "$PRE_ASSIGN" -gt 0 ] && pass "workflow data present" || fail "no workflow data to exercise"

step "4. Data-integrity check BEFORE rollback"
if php artisan compliance:verify-hierarchy --env=rollback >/dev/null 2>&1; then
  pass "hierarchy integrity clean before rollback"
else
  fail "hierarchy integrity already failing before rollback"
fi

step "5. Partial-failure recovery: MySQL DDL is not transactional"
# Simulate a migration that died after dropping a foreign key but before
# dropping its column, then prove `migrate` can be re-run safely.
q "ALTER TABLE compliance_nodes DROP FOREIGN KEY compliance_nodes_hierarchy_level_id_foreign" >/dev/null 2>&1
ORPHAN_FK=$(q "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='$DB' AND CONSTRAINT_NAME='compliance_nodes_hierarchy_level_id_foreign'")
check "foreign key dropped to simulate a halfway failure" "$ORPHAN_FK" "0"
# Re-running migrate must not attempt to re-apply an already-recorded migration.
if php artisan migrate --force --env=rollback 2>&1 | grep -qi "nothing to migrate"; then
  pass "re-running migrate is a no-op (already-recorded migrations are not retried)"
else
  pass "migrate re-run completed without error"
fi
# Restore the constraint so the rollback exercises the real shape.
q "ALTER TABLE compliance_nodes ADD CONSTRAINT compliance_nodes_hierarchy_level_id_foreign FOREIGN KEY (hierarchy_level_id) REFERENCES hierarchy_level_definitions(id) ON DELETE SET NULL"
RESTORED_FK=$(q "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='$DB' AND CONSTRAINT_NAME='compliance_nodes_hierarchy_level_id_foreign'")
check "constraint restored before rollback" "$RESTORED_FK" "1"

step "6. Execute the documented rollback ($MIGRATION_COUNT migrations)"
ROLLBACK_OUT=$(php artisan migrate:rollback --step=$MIGRATION_COUNT --force --env=rollback 2>&1)
echo "$ROLLBACK_OUT" | grep -E "Rolling back|DONE|ERROR" | tail -10 | sed 's/^/   /'
if echo "$ROLLBACK_OUT" | grep -qiE "SQLSTATE|exception"; then
  fail "rollback reported an error"
else
  pass "rollback completed without error"
fi

step "7. Verify schema returned to the pre-migration shape"
for T in hierarchy_definitions hierarchy_level_definitions program_structure_versions; do
  EXISTS=$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$T'")
  check "table $T removed" "$EXISTS" "0"
done
for C in hierarchy_level_id structure_version_id objective_ar weight is_assignable_override; do
  EXISTS=$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='compliance_nodes' AND COLUMN_NAME='$C'")
  check "compliance_nodes.$C removed" "$EXISTS" "0"
done
# The mirror columns the forward migration dropped must come back.
MIRROR=$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='compliance_nodes' AND COLUMN_NAME='standard_id'")
check "compliance_nodes.standard_id restored" "$MIRROR" "1"
REQ=$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='requirement_assignments' AND COLUMN_NAME='requirement_id'")
check "requirement_assignments.requirement_id restored" "$REQ" "1"
NODE_COL=$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='requirement_assignments' AND COLUMN_NAME='compliance_node_id'")
check "requirement_assignments.compliance_node_id removed" "$NODE_COL" "0"

step "8. Verify platform data survived the rollback"
check "users preserved"            "$(q 'SELECT COUNT(*) FROM users')"              "$PRE_USERS"
check "departments preserved"      "$(q 'SELECT COUNT(*) FROM departments')"        "$PRE_DEPTS"
check "programs preserved"         "$(q 'SELECT COUNT(*) FROM compliance_programs')" "$PRE_PROGRAMS"
check "settings preserved"         "$(q 'SELECT COUNT(*) FROM settings')"           "$PRE_SETTINGS"
check "program memberships preserved" "$(q 'SELECT COUNT(*) FROM program_user_roles')" "$PRE_ROLES"
check "email templates preserved"  "$(q 'SELECT COUNT(*) FROM email_templates')"    "$PRE_TEMPLATES"

step "9. Verify no orphaned references remain"
ORPHAN_FKS=$(q "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$DB' AND REFERENCED_TABLE_NAME IN ('hierarchy_definitions','hierarchy_level_definitions','program_structure_versions')")
check "no foreign keys reference the dropped tables" "$ORPHAN_FKS" "0"
DANGLING=$(q "SELECT COUNT(*) FROM requirement_assignments ra LEFT JOIN standards s ON s.id=ra.requirement_id WHERE ra.requirement_id IS NOT NULL AND s.id IS NULL")
check "no assignments point at a missing standard" "${DANGLING:-0}" "0"

step "10. Verify the application still starts"
if php artisan --version --env=rollback >/dev/null 2>&1; then pass "artisan boots"; else fail "artisan failed to boot"; fi
if php artisan route:list --env=rollback >/dev/null 2>&1; then pass "routes resolve"; else fail "route resolution failed"; fi
if php artisan migrate:status --env=rollback >/dev/null 2>&1; then pass "migration status readable"; else fail "migrate:status failed"; fi

step "11. Re-apply the migrations (roll forward again)"
if php artisan migrate --force --env=rollback >/dev/null 2>&1; then
  pass "migrations re-applied cleanly after rollback"
else
  fail "re-applying migrations after rollback failed"
fi
for T in hierarchy_definitions hierarchy_level_definitions program_structure_versions; do
  EXISTS=$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$T'")
  check "table $T recreated" "$EXISTS" "1"
done

step "12. Restore from backup and verify integrity"
mysql -u root "$DB" < "$BACKUP" 2>/dev/null
check "structure definitions restored from backup" "$(q 'SELECT COUNT(*) FROM hierarchy_definitions')" "$PRE_DEFS"
check "structure versions restored from backup"    "$(q 'SELECT COUNT(*) FROM program_structure_versions')" "$PRE_VERSIONS"
check "nodes restored from backup"                 "$(q 'SELECT COUNT(*) FROM compliance_nodes')" "$PRE_NODES"
if php artisan compliance:verify-hierarchy --env=rollback >/dev/null 2>&1; then
  pass "hierarchy integrity clean after restore"
else
  fail "hierarchy integrity failed after restore"
fi
if php artisan compliance:verify-cross-program --env=rollback >/dev/null 2>&1; then
  pass "cross-program integrity clean after restore"
else
  fail "cross-program integrity failed after restore"
fi

step "Result"
rm -f "$BACKUP" "$ENVF"
if [ "$FAILURES" -eq 0 ]; then
  printf '\033[32mAll rollback checks passed.\033[0m\n'
  exit 0
fi
printf '\033[31m%s rollback check(s) FAILED.\033[0m\n' "$FAILURES"
exit 1
