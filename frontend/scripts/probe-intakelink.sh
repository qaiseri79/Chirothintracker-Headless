#!/usr/bin/env bash
set -uo pipefail
M=/app/web/modules/custom/headless_custom/headless_patients/src/PatientIntakeService.php
echo "=== intakeLink() ==="
grep -n "intakeLink" -A 32 "$M" | head -50
echo
echo "=== constructor + create() ==="
sed -n '1,80p' "$M" | grep -n "use \|public function __construct\|private readonly"