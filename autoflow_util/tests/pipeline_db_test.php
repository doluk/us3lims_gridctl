<?php
/*
 * Database test for autoflow_util/pipeline_lib.php: creates a scratch LIMS
 * database from us3_sql/us3.sql and runs the scheduler, simulating the
 * status updates of submit_local.php / gridctl.php / cleanup_gfac.php.
 *
 * Run: php autoflow_util/tests/pipeline_db_test.php /path/to/us3_sql/us3.sql
 * Env: PL_TEST_DBHOST (localhost), PL_TEST_DBUSER (root), PL_TEST_DBPASS (''), PL_TEST_DB (uslims3_pltest)
 * The test database is dropped and recreated.
 */

require_once __DIR__ . '/../pipeline_lib.php';
require_once __DIR__ . '/test_util.php';

if ( count( $argv ) != 2 || !is_file( $argv[ 1 ] ) ) {
    echo "usage: php {$argv[0]} /path/to/us3_sql/us3.sql\n";
    exit( 2 );
}
if ( !getenv( "PL_TEST_STRICT" ) ) {
    mysqli_report( MYSQLI_REPORT_OFF );
}
$host = getenv( 'PL_TEST_DBHOST' ) ?: 'localhost';
$user = getenv( 'PL_TEST_DBUSER' ) ?: 'root';
$pass = getenv( 'PL_TEST_DBPASS' ) ?: '';
$tdb  = getenv( 'PL_TEST_DB' )     ?: 'uslims3_pltest';

$db = mysqli_connect( $host, $user, $pass );
if ( !$db ) {
    echo "cannot connect to $host as $user\n";
    exit( 2 );
}
pl_query( $db, "DROP DATABASE IF EXISTS `$tdb`" );
pl_query( $db, "CREATE DATABASE `$tdb`" );
mysqli_select_db( $db, $tdb );
## SOURCE lines are mysql client commands (reference data files), not needed here
$schema = preg_replace( '/^\s*SOURCE\s.*$/mi', '', file_get_contents( $argv[ 1 ] ) );
if ( !mysqli_multi_query( $db, $schema ) ) {
    echo "loading schema failed: " . mysqli_error( $db ) . "\n";
    exit( 2 );
}
do {
    if ( $r = mysqli_store_result( $db ) ) {
        mysqli_free_result( $r );
    }
} while ( mysqli_more_results( $db ) && mysqli_next_result( $db ) );
if ( mysqli_errno( $db ) ) {
    echo "loading schema failed: " . mysqli_error( $db ) . "\n";
    exit( 2 );
}
mysqli_select_db( $db, 'mysql' );   # like submitctl: connected elsewhere, qualified names

$L = "`$tdb`";
function q( $sql ) { global $db; return pl_query( $db, $sql ); }
function one( $sql ) { $r = q( $sql ); $o = mysqli_fetch_row( $r ); return $o ? $o[ 0 ] : null; }

## edits used by the datasets
q( "INSERT INTO $L.editedData (editedDataID, editGUID, filename) VALUES (555, UUID(), 'a.xml'), (556, UUID(), 'b.xml')" );

## a GMP autoflow row that must not be touched
q( "INSERT INTO $L.autoflowAnalysis SET tripleName='9.A.280', filename='gmp', aprofileGUID='11111111-1111-1111-1111-111111111111', " .
   "invID=1, statusJson='{\"to_process\":[\"2DSA\"]}', status='RUNNING', autoflowID=3" );

$spec = [
    'version' => 2, 'cluster' => 'us3iab-node0',
    'submitter' => [ 'personID' => 12, 'email' => 'a@b.c', 'userlevel' => 2 ],
    'requests' => [
        [ 'key' => '1.A.280', 'rawDataID' => 101, 'editedDataID' => 555, 'noiseIDs' => [ 77, 78 ], 'runID' => 'run1', 'triple' => '1.A.280' ],
        [ 'key' => '1.B.280', 'rawDataID' => 102, 'editedDataID' => 556, 'noiseIDs' => [], 'runID' => 'run1', 'triple' => '1.B.280' ],
    ],
    'params' => [ 's_value_max' => 10, 'band_volume-value' => 0.015 ],
    'stages' => [
        [ 'id' => 'it', 'method' => '2DSA_IT', 'params' => [ 'noise_fit' => 'ti+ri' ] ],
        [ 'id' => 'mc', 'method' => '2DSA_MC', 'after' => [ 'it' ], 'params' => [ 'mc_iterations' => 100 ] ],
        [ 'id' => 'pc', 'method' => 'PCSA', 'enabled' => false, 'after' => [ 'it' ] ],
    ],
    'datasets' => [ '1.B.280' => [ 'params' => [ 'band_volume-value' => 0.01 ], 'stages' => [ 'pc' => [ 'enabled' => true ] ] ] ],
    'groups' => [ [ 'name' => 'G1', 'members' => [ '1.A.280', '1.B.280' ],
                    'stages' => [ [ 'id' => 'glit', 'method' => '2DSA_GL_IT', 'after_member' => [ 'it' ] ] ] ] ],
];

$spawned = [];
$canceled = [];
$opts = [
    'spawn'   => function( $id ) use ( &$spawned ) { $spawned[] = $id; },
    'scancel' => function( $g ) use ( &$canceled ) { $canceled[] = $g; },
    'log'     => function( $m, $l = 0 ) { if ( getenv( 'PL_TEST_VERBOSE' ) ) echo "    log: $m\n"; },
];
function pass() {
    global $db, $tdb, $opts, $spawned;
    $spawned = [];
    return pl_scheduler_pass( $db, $tdb, $opts );
}
function rid( $pid, $key ) { global $L; return intval( one( "SELECT requestID FROM $L.autoflowAnalysis WHERE pipelineID=$pid AND JSON_VALUE(statusJson,'$.rowKey')='$key'" ) ); }
function st( $id ) { global $L; return one( "SELECT status FROM $L.autoflowAnalysis WHERE requestID=$id" ); }
function sj( $id ) { global $L; return json_decode( one( "SELECT statusJson FROM $L.autoflowAnalysis WHERE requestID=$id" ), true ); }

$hpc = 0;
## simulate a job: submit_local sets SUBMITTED, cleanup_gfac stores results, gridctl sets COMPLETE
function job_complete( $id, $noises = [] ) {
    global $L, $hpc;
    $hpc++;
    q( "INSERT INTO $L.HPCAnalysisRequest SET HPCAnalysisRequestID=$hpc, HPCAnalysisRequestGUID=UUID(), investigatorGUID='i', submitterGUID='s'" );
    q( "INSERT INTO $L.HPCAnalysisResult SET HPCAnalysisRequestID=$hpc" );
    $res = intval( one( "SELECT LAST_INSERT_ID()" ) );
    foreach ( $noises as $n ) {
        q( "INSERT INTO $L.noise SET noiseGUID=UUID(), editedDataID={$n[0]}, modelID=0, noiseType='{$n[1]}'" );
        $nid = intval( one( "SELECT LAST_INSERT_ID()" ) );
        q( "INSERT INTO $L.HPCAnalysisResultData SET HPCAnalysisResultID=$res, HPCAnalysisResultType='noise', resultID=$nid" );
    }
    q( "INSERT INTO $L.model SET modelGUID=UUID(), editedDataID=555" );
    $mid = intval( one( "SELECT LAST_INSERT_ID()" ) );
    q( "INSERT INTO $L.HPCAnalysisResultData SET HPCAnalysisResultID=$res, HPCAnalysisResultType='model', resultID=$mid" );
    q( "UPDATE $L.autoflowAnalysis SET status='COMPLETE', statusMsg='done', currentGfacID='job$id', currentHPCARID=$hpc WHERE requestID=$id" );
}

## ----------------------------------------------------------------
t_section( 'create pipeline' );
t_ok( pl_db_has_pipeline_schema( $db, $tdb ), 'schema detected' );
$pid = pl_create_pipeline( $db, $tdb, $spec, 'test pipeline', 12, 'us3iab-node0' );
t_ok( $pid > 0, "pipeline created ($pid)" );
t_eq( one( "SELECT status FROM $L.analysisPipeline WHERE pipelineID=$pid" ), 'ACTIVE', 'pipeline ACTIVE' );
t_eq( intval( one( "SELECT COUNT(*) FROM $L.autoflowAnalysis WHERE pipelineID=$pid" ) ), 6, '6 rows (2 it, 2 mc, 1 pc, 1 group)' );
t_eq( one( "SELECT CONCAT_WS('|',autoflowID,aprofileGUID,rowKind,stageKey,tripleName,status) FROM $L.autoflowAnalysis WHERE requestID=" . rid( $pid, 'd|1.A.280|it' ) ),
      '0|00000000-0000-0000-0000-000000000000|triple|it|1.A.280|PENDING', 'dataset row columns' );
$gl = rid( $pid, 'g|G1|glit' );
t_eq( one( "SELECT CONCAT_WS('|',rowKind,groupName,tripleName) FROM $L.autoflowAnalysis WHERE requestID=$gl" ), 'group|G1|G1', 'group row columns' );
t_eq( sj( $gl )[ 'after' ], [ rid( $pid, 'd|1.A.280|it' ), rid( $pid, 'd|1.B.280|it' ) ], 'group after = member it requestIDs' );
t_throws( function() use ( $spec ) { global $db, $tdb; $s = $spec; $s[ 'groups' ][ 0 ][ 'members' ] = [ '1.A.280' ];
                                     pl_create_pipeline( $db, $tdb, $s, 'bad', 12, 'c' ); }, 'invalid spec rejected' );
t_eq( intval( one( "SELECT COUNT(*) FROM $L.analysisPipeline" ) ), 1, 'nothing written for invalid spec' );

$itA = rid( $pid, 'd|1.A.280|it' ); $itB = rid( $pid, 'd|1.B.280|it' );
$mcA = rid( $pid, 'd|1.A.280|mc' ); $mcB = rid( $pid, 'd|1.B.280|mc' ); $pcB = rid( $pid, 'd|1.B.280|pc' );

t_section( 'pass 1: root stages ready' );
t_ok( pass(), 'work done' );
t_eq( $spawned, [ $itA, $itB ], 'both IT stages spawned (parallel)' );
t_eq( [ st( $itA ), st( $mcA ), st( $gl ) ], [ 'READY', 'PENDING', 'PENDING' ], 'statuses' );
$j = sj( $itA );
t_eq( $j[ 'inputs' ], [ 'datasets' => [ [ 'key' => '1.A.280', 'editedDataID' => 555, 'noiseIDs' => [ 77, 78 ] ] ] ], 'IT inputs: queue setup selection' );
t_eq( [ $j[ 'params' ][ 'noise_fit' ], $j[ 'submitted' ], $j[ 'revision' ] ], [ 'ti+ri', '2DSA_IT', 1 ], 'params, submitted tag, revision' );
t_eq( sj( $itB )[ 'params' ][ 'band_volume-value' ], 0.01, 'dataset override resolved' );
t_eq( one( "SELECT status FROM $L.autoflowAnalysis WHERE pipelineID IS NULL" ), 'RUNNING', 'GMP row untouched' );
t_ok( !pass(), 'second pass: nothing to do while jobs run' );
t_eq( $spawned, [], 'nothing spawned' );

t_section( 'pass 2: A finishes, B fails' );
job_complete( $itA, [ [ 555, 'ti_noise' ], [ 555, 'ri_noise' ] ] );
q( "UPDATE $L.autoflowAnalysis SET status='FAILED', statusMsg='node crashed', currentGfacID='jobB' WHERE requestID=$itB" );
pass();
t_eq( [ st( $itA ), st( $itB ) ], [ 'FINISHED', 'FAILED' ], 'A finished, B failed' );
t_eq( $canceled, [ 'jobB' ], 'failed job canceled on the cluster' );
t_eq( one( "SELECT currentGfacID FROM $L.autoflowAnalysis WHERE requestID=$itB" ), null, 'gfacID cleared' );
$out = sj( $itA )[ 'outputs' ];
t_eq( [ count( $out[ 'noises' ] ), count( $out[ 'models' ] ) ], [ 2, 1 ], 'outputs of A recorded' );
t_eq( $spawned, [ $mcA ], 'mc A spawned' );
t_eq( sj( $mcA )[ 'inputs' ][ 'datasets' ][ 0 ][ 'noiseIDs' ], array_map( function( $n ) { return $n[ 'noiseID' ]; }, [ $out[ 'noises' ][ 1 ], $out[ 'noises' ][ 0 ] ] ),
      'mc A noise = IT A noise (ri, ti)' );
t_eq( [ st( $mcB ), st( $pcB ), st( $gl ) ], [ 'BLOCKED', 'BLOCKED', 'BLOCKED' ], 'B successors and whole group blocked' );
t_eq( one( "SELECT status FROM $L.analysisPipeline WHERE pipelineID=$pid" ), 'ACTIVE', 'pipeline stays ACTIVE (needs attention)' );
t_eq( count( sj( $itB )[ 'processed' ] ), 1, 'failure recorded in processed' );
$canceled = [];
pass();
t_eq( $canceled, [], 'failed row handled only once' );

t_section( 'pass 3: retry B' );
q( "UPDATE $L.autoflowAnalysis SET status='PENDING', statusMsg='retry' WHERE requestID=$itB" );
pass();
t_eq( $spawned, [ $itB ], 'B re-spawned' );
t_eq( [ st( $mcB ), st( $pcB ), st( $gl ) ], [ 'PENDING', 'PENDING', 'PENDING' ], 'successors unblocked' );

t_section( 'pass 4: B finishes, consumers start in parallel' );
job_complete( $itB, [ [ 556, 'ti_noise' ] ] );
pass();
sort( $spawned );
t_eq( $spawned, [ $mcB, $pcB, $gl ], 'mc B, pc B and group stage start together' );
$g = sj( $gl )[ 'inputs' ][ 'datasets' ];
t_eq( [ $g[ 0 ][ 'key' ], count( $g[ 0 ][ 'noiseIDs' ] ), $g[ 1 ][ 'key' ], count( $g[ 1 ][ 'noiseIDs' ] ) ],
      [ '1.A.280', 2, '1.B.280', 1 ], 'group inputs: each member\'s IT noise' );
t_eq( sj( $gl )[ 'params' ][ 'datasets' ][ '1.B.280' ][ 'band_volume-value' ], 0.01, 'group dataset-level param per member' );

t_section( 'pass 5: all done, pipeline archived' );
foreach ( [ $mcA, $mcB, $pcB, $gl ] as $id ) {
    job_complete( $id );
}
pass();
t_eq( one( "SELECT status FROM $L.analysisPipeline WHERE pipelineID=$pid" ), 'FINISHED', 'pipeline FINISHED' );
t_eq( intval( one( "SELECT COUNT(*) FROM $L.autoflowAnalysis WHERE pipelineID=$pid" ) ), 0, 'no live rows' );
t_eq( intval( one( "SELECT COUNT(*) FROM $L.autoflowAnalysisHistory WHERE pipelineID=$pid AND status='FINISHED'" ) ), 6, '6 finished rows in history' );
t_eq( one( "SELECT status FROM $L.autoflowAnalysis WHERE pipelineID IS NULL" ), 'RUNNING', 'GMP row still untouched' );

t_section( 'cancel a pipeline with a running job' );
$pid2 = pl_create_pipeline( $db, $tdb, $spec, 'cancel me', 12, 'us3iab-node0' );
pass();
$itA2 = rid( $pid2, 'd|1.A.280|it' );
q( "UPDATE $L.autoflowAnalysis SET status='RUNNING', currentGfacID='jobX' WHERE requestID=$itA2" );
q( "UPDATE $L.analysisPipeline SET status='CANCELED' WHERE pipelineID=$pid2" );
q( "UPDATE $L.autoflowAnalysis SET status='CANCELED' WHERE pipelineID=$pid2 AND status NOT IN ('FINISHED','SKIPPED')" );
$canceled = [];
pass();
t_eq( $canceled, [ 'jobX' ], 'running job canceled on the cluster' );
t_eq( $spawned, [], 'nothing spawned for a canceled pipeline' );
t_eq( intval( one( "SELECT COUNT(*) FROM $L.autoflowAnalysisHistory WHERE pipelineID=$pid2 AND status='CANCELED'" ) ), 6, 'canceled rows archived' );
t_eq( one( "SELECT status FROM $L.analysisPipeline WHERE pipelineID=$pid2" ), 'CANCELED', 'pipeline stays CANCELED' );

t_section( 'SETUP pipelines are ignored; throttle' );
$pid3 = pl_create_pipeline( $db, $tdb, $spec, 'setup', 12, 'c' );
q( "UPDATE $L.analysisPipeline SET status='SETUP' WHERE pipelineID=$pid3" );
pass();
t_eq( $spawned, [], 'SETUP pipeline not scheduled' );
q( "UPDATE $L.analysisPipeline SET status='ACTIVE' WHERE pipelineID=$pid3" );
$opts[ 'max_active' ] = 1;
pass();
t_eq( count( $spawned ), 1, 'max_active=1: one stage started' );
unset( $opts[ 'max_active' ] );

t_section( 'requestIDs stay unique across history' );
$maxh = intval( one( "SELECT MAX(requestID) FROM $L.autoflowAnalysisHistory" ) );
q( "DELETE FROM $L.autoflowAnalysis WHERE pipelineID=$pid3" );
q( "UPDATE $L.analysisPipeline SET status='CANCELED' WHERE pipelineID=$pid3" );
$pid4 = pl_create_pipeline( $db, $tdb, $spec, 'ids', 12, 'c' );
t_ok( intval( one( "SELECT MIN(requestID) FROM $L.autoflowAnalysis WHERE pipelineID=$pid4" ) ) > $maxh, 'new requestIDs above history maximum' );

t_section( 'viewer actions' );
$p5 = pl_create_pipeline( $db, $tdb, $spec, 'actions', 12, 'us3iab-node0' );
pass();
$it5 = rid( $p5, 'd|1.A.280|it' ); $mc5 = rid( $p5, 'd|1.A.280|mc' ); $itB5 = rid( $p5, 'd|1.B.280|it' );
$rev = function( $id ) { return intval( sj( $id )[ 'revision' ] ); };
t_eq( pl_row_action( $db, $tdb, $p5, $it5, 'cancel', $rev( $it5 ) )[ 0 ], false, 'cancel refused while READY (being submitted)' );
q( "UPDATE $L.autoflowAnalysis SET status='RUNNING', currentGfacID='jobR' WHERE requestID=$it5" );
t_eq( pl_row_action( $db, $tdb, $p5, $it5, 'cancel', $rev( $it5 ) - 1 )[ 0 ], false, 'stale revision refused' );
t_eq( pl_row_action( $db, $tdb, $p5, $it5, 'cancel', $rev( $it5 ) ), [ true, 'stage it (1.A.280): Canceled by user' ], 'running stage canceled' );
$canceled = [];
pass();
t_eq( [ $canceled, st( $mcA = $mc5 ) ], [ [ 'jobR' ], 'BLOCKED' ], 'scheduler cancels the cluster job, successor blocked' );
t_eq( pl_row_action( $db, $tdb, $p5, $mc5, 'retry', $rev( $mc5 ) )[ 0 ], false, 'blocked stage can not be retried directly' );
t_eq( pl_row_action( $db, $tdb, $p5, $it5, 'retry', $rev( $it5 ) )[ 0 ], true, 'canceled stage retried' );
pass();
t_eq( [ st( $it5 ), st( $mc5 ) ], [ 'READY', 'PENDING' ], 'retried stage resubmitted, successor unblocked' );
t_eq( pl_row_action( $db, $tdb, $p5, $mc5, 'skip', $rev( $mc5 ) )[ 0 ], true, 'leaf stage skipped' );
t_eq( st( $mc5 ), 'SKIPPED', 'status SKIPPED' );
t_eq( pl_row_action( $db, $tdb, $p5, $mc5, 'unskip', $rev( $mc5 ) )[ 0 ], true, 'unskipped' );
$pcB5 = rid( $p5, 'd|1.B.280|pc' );
$r = pl_row_action( $db, $tdb, $p5, $pcB5, 'skip', $rev( $pcB5 ) );
t_eq( $r[ 0 ], true, 'pc skipped (nothing waits for it)' );
q( "UPDATE $L.autoflowAnalysis SET status='PENDING' WHERE requestID=$itB5" );
$r = pl_row_action( $db, $tdb, $p5, $itB5, 'skip', $rev( $itB5 ) );
t_ok( !$r[ 0 ] && strpos( $r[ 1 ], 'waits for this stage' ) !== false, 'stage with successors can not be skipped' );

t_section( 'listing and cancel pipeline' );
$l = pl_list_pipelines( $db, $tdb, 12 );
t_eq( intval( $l[ 0 ][ 'pipelineID' ] ), $p5, 'newest pipeline first' );
t_eq( count( pl_list_pipelines( $db, $tdb, 999 ) ), 0, 'other person sees nothing' );
t_eq( count( pl_get_pipeline_rows( $db, $tdb, $pid ) ), 6, 'archived pipeline rows readable from history' );
t_eq( pl_get_pipeline( $db, $tdb, $p5 )[ 'spec' ][ 'version' ], 2, 'spec decoded' );
q( "UPDATE $L.autoflowAnalysis SET status='SUBMITTED', currentGfacID='jobS' WHERE requestID=$it5" );
t_eq( pl_cancel_pipeline( $db, $tdb, $p5 )[ 0 ], true, 'pipeline canceled' );
t_eq( st( $mc5 ), 'CANCELED', 'waiting stage canceled immediately' );
t_eq( st( $it5 ), 'SUBMITTED', 'submitted stage left to the scheduler' );
$canceled = [];
pass();
t_eq( $canceled, [ 'jobS' ], 'scheduler cancels the submitted job' );
t_eq( intval( one( "SELECT COUNT(*) FROM $L.autoflowAnalysisHistory WHERE pipelineID=$p5" ) ), 6, 'canceled pipeline archived' );
t_eq( pl_row_action( $db, $tdb, $p5, $it5, 'retry', 0 )[ 0 ], false, 'no actions on archived rows' );

t_section( 'LIMS DB user without ALTER privilege' );
pl_query( $db, "CREATE USER IF NOT EXISTS 'pl_noalter'@'localhost' IDENTIFIED BY 'x'" );
pl_query( $db, "GRANT SELECT, INSERT, UPDATE, DELETE ON `$tdb`.* TO 'pl_noalter'@'localhost'" );
$na = mysqli_connect( $host, 'pl_noalter', 'x' );
$p6 = pl_create_pipeline( $na, $tdb, $spec, 'no alter', 12, 'c' );
t_ok( $p6 > 0, 'created when AUTO_INCREMENT is already above the history maximum' );
q( "INSERT INTO $L.autoflowAnalysisHistory SELECT * FROM $L.autoflowAnalysis WHERE pipelineID=$p6" );
q( "UPDATE $L.autoflowAnalysisHistory SET requestID=requestID+1000 WHERE pipelineID=$p6" );
q( "DELETE FROM $L.autoflowAnalysis WHERE pipelineID=$p6" );
t_throws( function() use ( $na, $tdb, $spec ) { pl_create_pipeline( $na, $tdb, $spec, 'x', 12, 'c' ); }, 'refused when requestIDs would be reused' );
pl_query( $db, "DROP USER 'pl_noalter'@'localhost'" );

pl_query( $db, "DROP DATABASE `$tdb`" );
t_done();
