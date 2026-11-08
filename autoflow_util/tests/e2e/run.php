<?php
/*
 * End-to-end: pipeline_lib + submitctl pass + submitone.php -> real LIMS pages (2DSA_1/2, 2DSA-CG_1/2, PCSA_1/2)
 * -> HPCAnalysisRequest / HPCDataset / HPCRequestData / job xml; job submission stubbed (submit_local).
 */
require __DIR__ . '/../../pipeline_lib.php';
require __DIR__ . '/../test_util.php';
mysqli_report( MYSQLI_REPORT_OFF );
$E = getenv( 'E2E_OUT' ) ?: '/tmp/pipeline_e2e'; $GRIDCTL = getenv( 'GRIDCTL' ); $tdb = 'uslims3_e2e'; $L = "`$tdb`";
$db = mysqli_connect( 'localhost', 'root', '' );
function q( $sql ) { global $db; return pl_query( $db, $sql ); }
function one( $sql ) { $r = q( $sql ); $o = mysqli_fetch_row( $r ); return $o ? $o[0] : null; }
function rows( $sql ) { $r = q( $sql ); $o = []; while ( $x = mysqli_fetch_assoc( $r ) ) $o[] = $x; return $o; }
$spawned = [];
function pass() { global $db, $tdb, $spawned; $spawned = [];
    pl_scheduler_pass( $db, $tdb, [ 'spawn' => function( $id ) use ( &$spawned ) { $spawned[] = $id; } ] ); return $spawned; }
function submit( $id ) {
    global $tdb, $E, $GRIDCTL;
    exec( "cd /home/us3/lims/bin && php $GRIDCTL/submitone.php $tdb $id 2>&1", $out, $rc );
    file_put_contents( "$E/submitone_$id.out", implode( "\n", $out ) );
    return $rc;
}
function request_of( $id ) {   # HPC request written for this row (the stub marks the row SUBMITTED)
    global $L;
    $x = rows( "SELECT r.HPCAnalysisRequestID AS id, r.method, r.analType, r.clusterName, r.email, r.requestXMLFile AS xml_raw FROM $L.HPCAnalysisRequest r ORDER BY r.HPCAnalysisRequestID DESC LIMIT 1" )[0] ?? null; if ( $x ) $x["xml"] = stripslashes( $x["xml_raw"] ); return $x;
}
## simulate cleanup_gfac + gridctl: results stored, COMPLETE
function complete( $id, $hpc, $editNoise, $itModels = [] ) {
    global $L;
    q( "INSERT INTO $L.HPCAnalysisResult SET HPCAnalysisRequestID=$hpc" );
    $res = intval( one( "SELECT LAST_INSERT_ID()" ) );
    foreach ( $editNoise as $ed ) {
        q( "INSERT INTO $L.noise SET noiseGUID=UUID(), editedDataID=$ed, modelID=0, noiseType='ti_noise', xml='<noise/>'" );
        q( "INSERT INTO $L.HPCAnalysisResultData SET HPCAnalysisResultID=$res, HPCAnalysisResultType='noise', resultID=LAST_INSERT_ID()" );
    }
    foreach ( $itModels as $ed ) {   # the 2DSA-IT model the LIMS needs for global fits (total concentration)
        q( "INSERT INTO $L.model SET modelGUID=UUID(), editedDataID=$ed, description='run1.1A280.e1.2DSA-IT_01.model', xml='<ModelData><model><analyte signal=\"0.7\"/></model></ModelData>'" );
        q( "INSERT INTO $L.HPCAnalysisResultData SET HPCAnalysisResultID=$res, HPCAnalysisResultType='model', resultID=LAST_INSERT_ID()" );
    }
    q( "UPDATE $L.autoflowAnalysis SET status='COMPLETE', currentHPCARID=$hpc WHERE requestID=$id" );
}
function datasets_of( $hpc ) {
    global $L;
    return rows( "SELECT d.editedDataID, d.band_volume, d.simpoints, (SELECT GROUP_CONCAT(noiseID ORDER BY noiseID) FROM $L.HPCRequestData n WHERE n.HPCDatasetID=d.HPCDatasetID) AS noise " .
                 "FROM $L.HPCDataset d WHERE d.HPCAnalysisRequestID=$hpc ORDER BY d.HPCDatasetID" );
}

$spec = [
  'version' => 2, 'cluster' => 'us3iab-node0',
  'submitter' => [ 'personID' => 12, 'email' => 'ada@example.org', 'submitter_email' => 'ada@example.org', 'userlevel' => 2, 'advancelevel' => 0 ],
  'requests' => [],
  'params' => [ 's_value_min' => 1, 's_value_max' => 10, 's_grid_points' => 50, 'ff0_grid_points' => 50, 'band_volume-value' => 0.015 ],
  'stages' => [
    [ 'id' => 'it', 'method' => '2DSA_IT', 'params' => [ 'noise_fit' => 'ti', 'max_iterations' => 3 ] ],
    [ 'id' => 'mc', 'method' => '2DSA_MC', 'after' => [ 'it' ], 'params' => [ 'mc_iterations' => 20 ] ],
    [ 'id' => 'cg', 'method' => '2DSA',    'enabled' => false, 'after' => [ 'it' ], 'params' => [ 'grid' => [ 'type' => 'custom', 'modelID' => 4711 ] ] ],
    [ 'id' => 'pc', 'method' => 'PCSA',    'enabled' => false, 'after' => [ 'it' ], 'params' => [ 'curve_type' => 'IS', 'pcsa_solute_type' => '013.skv' ] ],
  ],
  'datasets' => [
    '1.B.280' => [ 'stages' => [ 'cg' => [ 'enabled' => true ] ] ],
    '2.A.280' => [ 'params' => [ 'band_volume-value' => 0.010 ], 'stages' => [ 'pc' => [ 'enabled' => true ] ] ],
  ],
  'groups' => [
    [ 'name' => 'G1', 'members' => [ '1.A.280', '1.B.280', '2.A.280' ], 'params' => [ 's_value_max' => 20 ],
      'stages' => [ [ 'id' => 'glit', 'method' => '2DSA_GL_IT', 'after_member' => [ 'it' ], 'params' => [ 'max_iterations' => 2 ] ],
                    [ 'id' => 'glpc', 'method' => 'PCSA_GL',    'after_member' => [ 'it' ] ] ] ],
  ],
];
foreach ( [ [ '1.A.280', 101, 555, [ 77 ] ], [ '1.B.280', 102, 556, [] ], [ '2.A.280', 103, 557, [] ] ] as $r ) {
    list( $c, $ch, $w ) = explode( '.', $r[0] );
    $spec[ 'requests' ][] = [ 'key' => $r[0], 'triple' => $r[0], 'runID' => 'run1', 'rawDataID' => $r[1], 'editedDataID' => $r[2], 'noiseIDs' => $r[3],
        'path' => '/data/run1', 'filename' => "run1.RA.$c.$ch.$w.auc", 'editFilename' => "run1.e1.RA.$c.$ch.$w.xml",
        'editMeniscus' => 5.85, 'dataLeft' => 5.9, 'dataRight' => 7.1 ];
}

t_section( 'dataset stages through the real LIMS pages' );
$pid = pl_create_pipeline( $db, $tdb, $spec, 'e2e', 12, 'us3iab-node0' );
$its = pass();
t_eq( count( $its ), 3, 'three 2DSA_IT rows ready' );
$hpc = [];
foreach ( $its as $id ) {
    $rc = submit( $id );
    t_eq( one( "SELECT status FROM $L.autoflowAnalysis WHERE requestID=$id" ), 'SUBMITTED', "row $id submitted by 2DSA_2.php" );
    t_eq( $rc, 0, "row $id: submitone.php exit code 0" );
    $r = request_of( $id ); $hpc[ $id ] = intval( $r['id'] );
}
$r = rows( "SELECT method, analType, clusterName, email FROM $L.HPCAnalysisRequest WHERE HPCAnalysisRequestID={$hpc[$its[0]]}" )[0];
t_eq( [ $r['method'], $r['clusterName'], $r['email'] ], [ '2DSA', 'node0.example.org', 'ada@example.org' ], 'HPC request: method, cluster, user e-mail' );
t_ok( strpos( $r['analType'], 'IT' ) !== false, "analType {$r['analType']} marks iterations" );
t_eq( datasets_of( $hpc[ $its[0] ] ), [ [ 'editedDataID' => '555', 'band_volume' => '0', 'simpoints' => '200', 'noise' => '77' ] ],
      'A: pinned Queue Setup noise 77, standard centerpiece (band volume 0)' );
t_eq( datasets_of( $hpc[ $its[2] ] )[0]['band_volume'], '0.01', 'C: band-forming centerpiece gets the dataset override 0.010' );
$x = stripslashes( one( "SELECT requestXMLFile FROM $L.HPCAnalysisRequest WHERE HPCAnalysisRequestID={$hpc[$its[0]]}" ) );
t_ok( preg_match( '/max_iterations[^>]*value="3"/', $x ) && preg_match( '/tinoise_option[^>]*value="1"/', $x ), 'job xml: max_iterations 3, TI noise fit' );

## IT finished: noise + 2DSA-IT model per edit
foreach ( $its as $i => $id ) {
    $ed = [ 555, 556, 557 ][ $i ];
    complete( $id, $hpc[ $id ], [ $ed ], [ $ed ] );
}
$next = pass();
$byKey = [];
foreach ( rows( "SELECT requestID, JSON_VALUE(statusJson,'$.rowKey') AS k FROM $L.autoflowAnalysis WHERE pipelineID=$pid" ) as $x ) $byKey[ $x['k'] ] = intval( $x['requestID'] );
sort( $next );
$exp = [ $byKey['d|1.A.280|mc'], $byKey['d|1.B.280|mc'], $byKey['d|1.B.280|cg'], $byKey['d|2.A.280|mc'], $byKey['d|2.A.280|pc'], $byKey['g|G1|glit'], $byKey['g|G1|glpc'] ];
sort( $exp );
t_eq( $next, $exp, 'all consumers (MC, CG, PCSA, both global stages) ready together' );

foreach ( [ 'd|1.A.280|mc' => '2DSA', 'd|1.B.280|cg' => '2DSA_CG', 'd|2.A.280|pc' => 'PCSA' ] as $k => $method ) {
    $id = $byKey[ $k ];
    submit( $id );
    t_eq( one( "SELECT status FROM $L.autoflowAnalysis WHERE requestID=$id" ), 'SUBMITTED', "$k submitted" );
    $r = request_of( $id );
    t_eq( $r['method'], $method, "$k: HPC method $method" );
    $ds = datasets_of( $r['id'] );
    $itNoise = one( "SELECT MAX(noiseID) FROM $L.noise WHERE editedDataID={$ds[0]['editedDataID']}" );
    t_eq( $ds[0]['noise'], $itNoise, "$k: noise of its own 2DSA_IT stage" );
    if ( $k == 'd|1.A.280|mc' ) t_ok( preg_match( '/mc_iterations[^>]*value="20"/', $r['xml'] ), 'MC: mc_iterations 20 in job xml' );
    if ( $k == 'd|1.B.280|cg' ) t_ok( strpos( $r['xml'], '4711' ) !== false, 'CG: custom grid model 4711 in job xml' );
}

t_section( 'global (group) stages through the real LIMS pages' );
foreach ( [ 'g|G1|glit' => '2DSA', 'g|G1|glpc' => 'PCSA' ] as $k => $method ) {
    $id = $byKey[ $k ];
    $rc = submit( $id );
    t_eq( one( "SELECT status FROM $L.autoflowAnalysis WHERE requestID=$id" ), 'SUBMITTED', "$k submitted" );
    t_eq( $rc, 0, "$k: submitone.php exit code 0" );
    $r = request_of( $id );
    t_eq( $r['method'], $method, "$k: method $method" );
    t_ok( strpos( $r['analType'], 'GL' ) !== false, "$k: analType {$r['analType']} is a global fit" );
    $ds = datasets_of( $r['id'] );
    t_eq( array_column( $ds, 'editedDataID' ), [ '555', '556', '557' ], "$k: one HPC request with all 3 member datasets" );
    t_eq( array_column( $ds, 'band_volume' ), [ '0', '0', '0.01' ], "$k: per-member band volume (dataset-level params)" );
    $itn = array_map( function( $e ) { return one( "SELECT MAX(noiseID) FROM `uslims3_e2e`.noise WHERE editedDataID=$e" ); }, [ 555, 556, 557 ] );
    t_eq( array_column( $ds, 'noise' ), $itn, "$k: each member's own 2DSA_IT noise" );
    if ( $method == '2DSA' ) {
        t_ok( preg_match( '/<s_max value="20"/', $r['xml'] ) && preg_match( '/<global_fit value="1"/', $r['xml'] ) && substr_count( $r['xml'], 'total_concentration value="0.7"' ) == 3 && preg_match( '/max_iterations[^>]*value="2"/', $r['xml'] ), 'GL-IT: group job params (s_max 20, 2 iterations)' );
        t_ok( preg_match( '/tinoise_option[^>]*value="0"/', $r['xml'] ), 'GL-IT: no noise fitting' );
        t_eq( substr_count( $r['xml'], '<dataset' ) >= 3, true, 'GL-IT: 3 datasets in the job xml' );
    }
}
t_eq( count( file( "$E/submitted.log" ) ), 8, '8 jobs handed to the (stub) cluster submission' );
t_done();
