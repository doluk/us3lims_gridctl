#!/bin/bash
#
# Browser test of pipeline_setup.php / pipeline_viewer.php (Playwright + Chromium).
# Run run.sh first (same environment variables, same WARNING: disposable containers only).
#
# Usage: DBINST=... US3SQL=... US3LIMS_COMMON=... autoflow_util/tests/e2e/ui_run.sh
#
set -e
E=$(cd "$(dirname "$0")" && pwd)
GRIDCTL=$(cd "$E/../../.." && pwd)
OUT=${E2E_OUT:-/tmp/pipeline_e2e}
I=/srv/www/htdocs/uslims3/uslims3_e2e
mkdir -p "$OUT"
# library where the LIMS looks for it ($class_dir), test login, shared files linked as in production
cp "$GRIDCTL/autoflow_util/pipeline_lib.php" /srv/www/htdocs/common/class/pipeline_lib.php
cp "$E/pltest_login.php" $I/
for f in footer.php topmenu.php contacts.php license.php current_db_list.php; do ln -sf ../$f $I/$f; done
mariadb gfac -e "DELETE FROM cluster_status; INSERT INTO cluster_status SET cluster='us3iab-node0', running=1, queued=0, status='up'"
mariadb uslims3_e2e -e "DELETE FROM autoflowAnalysis; DELETE FROM analysisPipeline; DELETE FROM autoflowAnalysisHistory"
php -S 127.0.0.1:8099 -t /srv/www/htdocs > "$OUT/server.log" 2>&1 &
SERVER=$!
trap "kill $SERVER" EXIT
sleep 1
GRIDCTL="$GRIDCTL" node "$E/ui_test.js" "$OUT"
