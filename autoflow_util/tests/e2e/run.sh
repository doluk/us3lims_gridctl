#!/bin/bash
#
# End-to-end test of the analysis pipeline against the REAL us3lims_dbinst pages
# (2DSA_1/2, 2DSA-CG_1/2, PCSA_1/2); cluster submission is stubbed (submit_local).
#
# WARNING: modifies the machine - creates user us3, /home/us3/lims, /srv/www/htdocs/{uslims3,common},
# MariaDB databases uslims3_e2e/gfac and MariaDB user gfac. Use a disposable container only.
#
# Requires: MariaDB (root via unix socket), php-cli with mysqli, checkouts of
#   DBINST=<us3lims_dbinst>  US3SQL=<us3_sql>  US3LIMS_COMMON=<us3lims_common>
#
# Usage: DBINST=... US3SQL=... US3LIMS_COMMON=... autoflow_util/tests/e2e/run.sh
#
set -e
E=$(cd "$(dirname "$0")" && pwd)
GRIDCTL=$(cd "$E/../../.." && pwd)
: "${DBINST:?set DBINST to a us3lims_dbinst checkout}"
: "${US3SQL:?set US3SQL to a us3_sql checkout}"
: "${US3LIMS_COMMON:?set US3LIMS_COMMON to a us3lims_common checkout}"
OUT=${E2E_OUT:-/tmp/pipeline_e2e}
mkdir -p "$OUT"
DB=uslims3_e2e
W=/srv/www/htdocs/uslims3
C=/srv/www/htdocs/common
id us3 >/dev/null 2>&1 || useradd -m -d /home/us3 us3
mkdir -p /home/us3/lims/bin /home/us3/lims/etc/submit $W/uslims3_data $C/class
printf '[gfac]\npassword=\n[us3php]\npassword=\n' > /home/us3/lims/.us3lims.ini
cat > /home/us3/lims/bin/listen-config.php <<PHP
<?php
\$home = "/home/us3/lims"; \$logfile = "\$home/etc/udp.log";
\$dbhost = "localhost"; \$user = "root"; \$passwd = "";
\$class_dir = "$C/class/"; \$host_name = "e2ehost";
\$self = basename( \$_SERVER['PHP_SELF'] );
function write_log( \$m ) { global \$logfile; file_put_contents( \$logfile, \$m . "\n", FILE_APPEND ); }
PHP
# shared LIMS files (one level above the instance), cluster config
for f in footer.php topmenu.php contacts.php license.php current_db_list.php; do cp $US3LIMS_COMMON/$f $W/; done
cat > $W/cluster_config.php <<'PHP'
<?php
$cluster_configuration = [ 'us3iab-node0' => [ 'active' => true, 'status' => 'exit' ] ];
PHP
cat > $C/global_config.php <<'PHP'
<?php
$admin_list = [ 'admin@example.org' ];
$cluster_details = [ 'us3iab-node0' => [ 'active' => true, 'airavata' => false, 'name' => 'node0.example.org',
                     'queue' => 'batch', 'maxtime' => 60, 'ppn' => 4, 'ppbj' => 4, 'maxproc' => 4 ] ];
PHP
# classes: real progress/priority, stubbed submission (records the job files instead of submitting)
cp $US3LIMS_COMMON/class/progress.php $US3LIMS_COMMON/class/priority.php $C/class/
echo '<?php' > $C/class/experiment_status.php
echo '<?php class submit_gfac {}' > $C/class/submit_gfac.php
echo '<?php class submit_airavata {}' > $C/class/submit_airavata.php
cat > $C/class/submit_local.php <<PHP
<?php
// e2e stub: records the parsed job file and marks the autoflow row SUBMITTED like the real update_db()
class submit_local {
  var \$messages = [];
  function clear() { \$this->messages = []; }
  function parse_input( \$f ) { \$this->file = getcwd() . "/\$f"; }
  function submit() {
    global \$ID, \$is_cli, \$link;
    \$xml = file_get_contents( \$this->file );
    file_put_contents( "$OUT/submitted.log", json_encode( [ 'ID' => \$ID, 'file' => \$this->file ] ) . "\n", FILE_APPEND );
    if ( \$is_cli && \$ID > 0 ) mysqli_query( \$link, "UPDATE autoflowAnalysis SET status='SUBMITTED', currentGfacID='e2e-\$ID' WHERE requestID=\$ID" );
  }
  function get_messages() { return \$this->messages; }
  function close_transport() {}
}
PHP
# instance: real dbinst code + test config.php
rm -rf $W/$DB; mkdir -p $W/$DB
(cd $DBINST && for f in $(ls -A); do [ -L "$f" ] || cp -r "$f" $W/$DB/; done)
sed -e "s/^\$dbusername *= *'';/\$dbusername = 'root';/" -e "s/^\$dbname *= *'';/\$dbname = '$DB';/" \
    -e "s#/srv/www/htdocs/uslims3/uslims3_instance/#$W/$DB/#g" \
    $DBINST/config.php.template > $W/$DB/config.php
sed -i "0,/^<?php/s//<?php\n\$is_cli = ( php_sapi_name() == \"cli\" );\ninclude_once __DIR__ . \"\/elog.php\";/" $W/$DB/config.php
grep -q "dbname = '$DB'" $W/$DB/config.php
mkdir -p $W/$DB/data

# database
mariadb -e "CREATE DATABASE IF NOT EXISTS gfac; CREATE USER IF NOT EXISTS 'gfac'@'localhost' IDENTIFIED BY ''; GRANT ALL ON gfac.* TO 'gfac'@'localhost'; FLUSH PRIVILEGES"
mariadb gfac < "$GRIDCTL/gfac.sql"
mariadb -e "DROP DATABASE IF EXISTS uslims3_e2e; CREATE DATABASE uslims3_e2e"
sed 's/^\s*SOURCE\s.*$//I' $US3SQL/us3.sql | mariadb uslims3_e2e && mariadb uslims3_e2e < $E/fixture.sql
rm -f $OUT/submitted.log $OUT/submitone_*.out /home/us3/lims/etc/submit/*; rm -rf /srv/www/htdocs/uslims3/uslims3_data/*

GRIDCTL="$GRIDCTL" E2E_OUT="$OUT" php "$E/run.php"
