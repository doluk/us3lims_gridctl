<?php
/*
 * Unit tests for autoflow_util/pipeline_lib.php (pure functions, no database).
 * Run: php autoflow_util/tests/pipeline_lib_test.php
 */

require_once __DIR__ . '/../pipeline_lib.php';
require_once __DIR__ . '/test_util.php';

## ---------------------------------------------------------------- fixtures

function base_spec() {
    return [
        'version' => 2,
        'cluster' => 'us3iab-node0',
        'submitter' => [ 'personID' => 12, 'email' => 'a@b.c', 'userlevel' => 2 ],
        'requests' => [
            [ 'key' => '1.A.280', 'rawDataID' => 101, 'editedDataID' => 555, 'noiseIDs' => [ 77, 78 ], 'filename' => 'run1.RA.1.A.280.auc' ],
            [ 'key' => '1.B.280', 'rawDataID' => 102, 'editedDataID' => 556, 'noiseIDs' => [] ],
        ],
        'params' => [ 's_value_min' => 1, 's_value_max' => 10, 'grid' => [ 'type' => 'uniform' ], 'band_volume-value' => 0.015 ],
        'stages' => [
            [ 'id' => 'it', 'method' => '2DSA_IT', 'enabled' => true, 'params' => [ 'noise_fit' => 'ti+ri', 'max_iterations' => 10 ] ],
            [ 'id' => 'mc', 'method' => '2DSA_MC', 'enabled' => true, 'after' => [ 'it' ], 'params' => [ 'mc_iterations' => 100 ] ],
            [ 'id' => 'pc', 'method' => 'PCSA', 'enabled' => false, 'after' => [ 'it' ],
              'params' => [ 'curve_type' => 'IS', 'pcsa_solute_type' => '013.skv', 'vars_count' => 10 ] ],
        ],
        'datasets' => [
            '1.B.280' => [ 'params' => [ 'band_volume-value' => 0.010 ],
                           'stages' => [ 'mc' => [ 'params' => [ 'mc_iterations' => 50 ] ], 'pc' => [ 'enabled' => true ] ] ],
        ],
        'groups' => [
            [ 'name' => 'G1', 'members' => [ '1.A.280', '1.B.280' ], 'params' => [ 's_value_max' => 20 ],
              'stages' => [
                  [ 'id' => 'glit', 'method' => '2DSA_GL_IT', 'after_member' => [ 'it' ], 'params' => [ 'max_iterations' => 5 ] ],
                  [ 'id' => 'glmc', 'method' => '2DSA_GL_MC', 'after_member' => [ 'it' ], 'params' => [ 'mc_iterations' => 50 ] ],
              ] ],
        ],
    ];
}

function expect_error( $spec, $pattern, $name ) {
    $e = pl_expand_spec( $spec );
    $hit = false;
    foreach ( $e[ 'errors' ] as $m ) {
        if ( preg_match( $pattern, $m ) ) {
            $hit = true;
        }
    }
    t_ok( $hit, "$name (errors: " . implode( ' | ', $e[ 'errors' ] ) . ")" );
    t_eq( $e[ 'rows' ], [], "$name: no rows on error" );
}

## ---------------------------------------------------------------- expansion

t_section( 'expansion of the example spec' );
$e = pl_expand_spec( base_spec() );
t_eq( $e[ 'errors' ], [], 'example spec valid' );
t_eq( array_keys( $e[ 'rows' ] ),
      [ 'd|1.A.280|it', 'd|1.B.280|it', 'd|1.A.280|mc', 'd|1.B.280|mc', 'd|1.B.280|pc', 'g|G1|glit', 'g|G1|glmc' ],
      'rows in topological order, pc only for 1.B.280' );
t_eq( $e[ 'rows' ][ 'd|1.A.280|mc' ][ 'after' ], [ 'd|1.A.280|it' ], 'mc after it' );
t_eq( $e[ 'rows' ][ 'd|1.A.280|mc' ][ 'noise_from' ], [ 'row' => 'd|1.A.280|it' ], 'mc default noise from it (fits noise)' );
t_eq( $e[ 'rows' ][ 'd|1.A.280|it' ][ 'noise_from' ], 'queue_setup', 'root stage default noise: queue setup' );
t_eq( $e[ 'rows' ][ 'g|G1|glit' ][ 'after' ], [ 'd|1.A.280|it', 'd|1.B.280|it' ], 'group stage after all member its' );
t_eq( $e[ 'rows' ][ 'g|G1|glit' ][ 'noise_from' ],
      [ 'member_stage' => 'it', 'rows' => [ '1.A.280' => 'd|1.A.280|it', '1.B.280' => 'd|1.B.280|it' ] ],
      'group default noise from member it' );
t_eq( $e[ 'rows' ][ 'g|G1|glmc' ][ 'after' ], [ 'd|1.A.280|it', 'd|1.B.280|it' ], 'glmc independent of glit (parallel)' );

t_section( 'disabled stages are re-linked' );
$s = base_spec();
$s[ 'stages' ] = [
    [ 'id' => 'a',  'method' => '2DSA', 'params' => [ 'noise_fit' => 'ti' ] ],
    [ 'id' => 'it', 'method' => '2DSA_IT', 'after' => [ 'a' ], 'params' => [ 'noise_fit' => 'ti' ] ],
    [ 'id' => 'mc', 'method' => '2DSA_MC', 'after' => [ 'it' ], 'params' => [ 'mc_iterations' => 10 ] ],
];
$s[ 'datasets' ] = [ '1.B.280' => [ 'stages' => [ 'it' => [ 'enabled' => false ] ] ] ];
$s[ 'groups' ] = [];
$e = pl_expand_spec( $s );
t_eq( $e[ 'errors' ], [], 'valid' );
t_eq( $e[ 'rows' ][ 'd|1.B.280|mc' ][ 'after' ], [ 'd|1.B.280|a' ], 'mc re-linked to 2DSA when IT disabled' );
t_eq( $e[ 'rows' ][ 'd|1.B.280|mc' ][ 'noise_from' ], [ 'row' => 'd|1.B.280|a' ], 'default noise from nearest noise-fitting predecessor' );
t_eq( $e[ 'rows' ][ 'd|1.A.280|mc' ][ 'noise_from' ], [ 'row' => 'd|1.A.280|it' ], 'nearest is it for 1.A.280' );
t_ok( !isset( $e[ 'rows' ][ 'd|1.B.280|it' ] ), 'no row for disabled stage' );

t_section( 'explicit noise sources' );
$s = base_spec();
$s[ 'stages' ][ 1 ][ 'noise_from' ] = 'none';
$s[ 'datasets' ][ '1.B.280' ][ 'stages' ][ 'mc' ][ 'noise_from' ] = 'queue_setup';
$e = pl_expand_spec( $s );
t_eq( $e[ 'errors' ], [], 'valid' );
t_eq( $e[ 'rows' ][ 'd|1.A.280|mc' ][ 'noise_from' ], 'none', 'noise_from none' );
t_eq( $e[ 'rows' ][ 'd|1.B.280|mc' ][ 'noise_from' ], 'queue_setup', 'dataset override of noise_from' );

## ---------------------------------------------------------------- validation

t_section( 'validation' );
$s = base_spec(); $s[ 'version' ] = 1;
expect_error( $s, '/version/', 'wrong version' );

$s = base_spec(); $s[ 'params' ][ 'bogus' ] = 1;
expect_error( $s, "/unknown parameter 'bogus'/", 'unknown parameter' );

$s = base_spec(); $s[ 'params' ][ 'grid' ] = [ 'type' => 'custom' ];
expect_error( $s, '/custom grid requires a modelID/', 'custom grid without model' );

$s = base_spec(); $s[ 'params' ][ 'noise_fit' ] = 'xx';
expect_error( $s, '/noise_fit must be/', 'bad noise_fit' );

$s = base_spec(); $s[ 'stages' ][ 0 ][ 'method' ] = '2DSA_GL_IT';
expect_error( $s, '/not a dataset method/', 'group method in dataset template' );

$s = base_spec(); $s[ 'stages' ][] = [ 'id' => 'it', 'method' => '2DSA' ];
expect_error( $s, '/duplicate id/', 'duplicate stage id' );

$s = base_spec(); $s[ 'requests' ][ 1 ][ 'key' ] = '1.A.280';
expect_error( $s, '/duplicate key/', 'duplicate dataset key' );

$s = base_spec(); $s[ 'stages' ][ 1 ][ 'after' ] = [ 'zz' ];
expect_error( $s, "/unknown stage 'zz'/", 'after references unknown stage' );

## V1
$s = base_spec(); $s[ 'groups' ] = [];
$s[ 'stages' ] = [
    [ 'id' => 'it', 'method' => '2DSA_IT', 'params' => [ 'noise_fit' => 'ti' ] ],
    [ 'id' => 'mc', 'method' => '2DSA_MC', 'after' => [ 'it', 'pc' ], 'params' => [ 'mc_iterations' => 5 ] ],
    [ 'id' => 'pc', 'method' => 'PCSA', 'after' => [ 'mc' ] ],
];
$s[ 'datasets' ] = [];
expect_error( $s, '/cycle/', 'V1 cycle' );

## V2
$s = base_spec(); $s[ 'stages' ][ 1 ][ 'after' ] = [];
expect_error( $s, "/stage 'mc' must run after 'it'/", 'V2 consumer not after IT' );
$s = base_spec(); $s[ 'groups' ] = [];
$s[ 'stages' ] = [
    [ 'id' => 'a',  'method' => '2DSA' ],
    [ 'id' => 'it', 'method' => '2DSA_IT' ],
];
$s[ 'datasets' ] = [];
expect_error( $s, '/it must run after a/', 'V2 IT not after 2DSA' );
$s = base_spec(); $s[ 'stages' ][] = [ 'id' => 'it2', 'method' => '2DSA_IT', 'after' => [ 'it' ] ];
expect_error( $s, '/more than one enabled 2DSA_IT/', 'V2 two IT stages' );

## V4
$s = base_spec(); $s[ 'datasets' ][ '1.A.280' ][ 'stages' ][ 'it' ] = [ 'enabled' => false ];
$s[ 'datasets' ][ '1.A.280' ][ 'stages' ][ 'mc' ] = [ 'enabled' => false ];
expect_error( $s, "/disabled for member '1.A.280' \(V4\)/", 'V4 after_member disabled in a member' );

## V5
$s = base_spec(); $s[ 'stages' ][ 2 ][ 'enabled' ] = true; $s[ 'stages' ][ 2 ][ 'noise_from' ] = 'mc';
expect_error( $s, "/noise source 'mc' is not a preceding stage/", 'V5 noise source not a predecessor' );
$s = base_spec(); $s[ 'stages' ][ 0 ][ 'params' ][ 'noise_fit' ] = 'none'; $s[ 'stages' ][ 1 ][ 'noise_from' ] = 'it';
expect_error( $s, "/noise source 'it' does not fit noise/", 'V5 noise source does not fit noise' );
$s = base_spec(); $s[ 'groups' ][ 0 ][ 'stages' ][ 0 ][ 'params' ][ 'noise_fit' ] = 'ti';
expect_error( $s, '/global fits cannot fit noise/', 'V5 group stage noise fitting' );
$s = base_spec(); $s[ 'groups' ][ 0 ][ 'stages' ][ 0 ][ 'noise_from' ] = 'member:mc';
expect_error( $s, '/noise source must be/', 'V5 group noise source not in after_member' );
$s = base_spec(); $s[ 'groups' ][ 0 ][ 'stages' ][ 0 ][ 'noise_from' ] = 'queue_setup';
t_eq( pl_expand_spec( $s )[ 'rows' ][ 'g|G1|glit' ][ 'noise_from' ], 'queue_setup', 'V5 group noise from queue setup allowed' );

## V6
$s = base_spec(); $s[ 'groups' ][ 0 ][ 'members' ] = [ '1.A.280' ];
expect_error( $s, '/at least 2 members/', 'V6 single member group' );
$s = base_spec(); $s[ 'groups' ][ 0 ][ 'members' ][] = 'nope';
expect_error( $s, "/unknown member 'nope'/", 'V6 unknown member' );

## MC
$s = base_spec(); $s[ 'stages' ][ 1 ][ 'params' ][ 'mc_iterations' ] = 1; unset( $s[ 'datasets' ][ '1.B.280' ][ 'stages' ][ 'mc' ] );
expect_error( $s, '/needs mc_iterations > 1/', 'MC stage with 1 iteration' );
$s = base_spec(); $s[ 'params' ][ 'mc_iterations' ] = 20;
expect_error( $s, '/requires an _MC method/', 'mc_iterations on non-MC stage' );

## ---------------------------------------------------------------- params

t_section( 'parameter resolution' );
$s = base_spec();
$e = pl_expand_spec( $s )[ 'rows' ];
$p = pl_resolve_params( $s, $e[ 'd|1.B.280|mc' ] );
t_eq( $p[ 'band_volume-value' ], 0.010, 'L2 dataset override' );
t_eq( $p[ 'mc_iterations' ], 50, 'L3 dataset-stage override' );
t_eq( $p[ 's_value_max' ], 10, 'L0 template' );
$p = pl_resolve_params( $s, $e[ 'd|1.A.280|mc' ] );
t_eq( $p[ 'mc_iterations' ], 100, 'L1 stage template' );
t_eq( $p[ 'band_volume-value' ], 0.015, 'L0 for other dataset' );
$p = pl_resolve_params( $s, $e[ 'g|G1|glit' ] );
t_eq( $p[ 'job' ][ 's_value_max' ], 20, 'group job-level from group params' );
t_eq( $p[ 'job' ][ 'max_iterations' ], 5, 'group stage params' );
t_eq( $p[ 'job' ][ 'noise_fit' ], 'none', 'group never fits noise' );
t_eq( $p[ 'datasets' ][ '1.B.280' ], [ 'band_volume-value' => 0.010 ], 'group member dataset-level params' );
t_eq( $p[ 'datasets' ][ '1.A.280' ], [ 'band_volume-value' => 0.015 ], 'group member dataset-level default' );

## ---------------------------------------------------------------- inputs

t_section( 'input resolution' );
$sj = [ 'rowKind' => 'triple', 'datasetKey' => '1.A.280', 'noise_from' => 'queue_setup' ];
t_eq( pl_resolve_inputs( base_spec(), $sj, [] ),
      [ 'datasets' => [ [ 'key' => '1.A.280', 'editedDataID' => 555, 'noiseIDs' => [ 77, 78 ] ] ] ], 'queue setup noise' );
$sj[ 'noise_from' ] = 'none';
t_eq( pl_resolve_inputs( base_spec(), $sj, [] )[ 'datasets' ][ 0 ][ 'noiseIDs' ], [], 'no noise' );
$outputs = [ 'noises' => [
    [ 'noiseID' => 900, 'editedDataID' => 555, 'noiseType' => 'ti_noise' ],
    [ 'noiseID' => 905, 'editedDataID' => 555, 'noiseType' => 'ti_noise' ],
    [ 'noiseID' => 901, 'editedDataID' => 555, 'noiseType' => 'ri_noise' ],
    [ 'noiseID' => 950, 'editedDataID' => 556, 'noiseType' => 'ti_noise' ],
] ];
$sj[ 'noise_from' ] = [ 'requestID' => 40 ];
t_eq( pl_resolve_inputs( base_spec(), $sj, [ 40 => [ 'outputs' => $outputs ] ] )[ 'datasets' ][ 0 ][ 'noiseIDs' ],
      [ 901, 905 ], 'latest noise per type of the source row, for this edit only' );
t_throws( function() use ( $sj ) { pl_resolve_inputs( base_spec(), $sj, [ 40 => [ 'outputs' => null ] ] ); },
          'source without outputs throws' );
t_throws( function() use ( $sj ) { pl_resolve_inputs( base_spec(), $sj, [ 40 => [ 'outputs' => [ 'noises' => [] ] ] ] ); },
          'source without noise for this edit throws' );
$gj = [ 'rowKind' => 'group', 'members' => [ '1.A.280', '1.B.280' ],
        'noise_from' => [ 'member_stage' => 'it', 'rows' => [ '1.A.280' => 40, '1.B.280' => 41 ] ] ];
$in = pl_resolve_inputs( base_spec(), $gj, [ 40 => [ 'outputs' => $outputs ], 41 => [ 'outputs' => $outputs ] ] );
t_eq( $in[ 'datasets' ][ 1 ], [ 'key' => '1.B.280', 'editedDataID' => 556, 'noiseIDs' => [ 950 ] ], 'group: per member noise' );

## ---------------------------------------------------------------- POST

t_section( 'LIMS POST builder' );
$b = pl_build_post( '2DSA_IT', [ 's_value_max' => 20, 'noise_fit' => 'ti+ri', 'max_iterations' => 7, 'band_volume-value' => 0.01 ], 'h:c:q' );
t_eq( $b[ 'page' ], '2DSA_1.php', '2DSA page' );
t_eq( [ $b[ 'post' ][ 'iterations_option' ], $b[ 'post' ][ 'max_iterations' ], $b[ 'post' ][ 'mc_iterations' ] ], [ "1", "7", "1" ], 'IT: iterations on, mc 1' );
t_eq( [ $b[ 'post' ][ 'tinoise_option' ], $b[ 'post' ][ 'rinoise_option' ] ], [ "1", "1" ], 'TI+RI noise' );
t_eq( [ $b[ 'post' ][ 's_value_max' ], $b[ 'post' ][ 'band_volume-value' ], $b[ 'post' ][ 'cluster' ] ], [ "20", "0.01", "h:c:q" ], 'params and cluster' );
t_eq( $b[ 'post' ][ 'fit_mb_select' ], "0", 'no meniscus fit' );
$b = pl_build_post( '2DSA_MC', [ 'mc_iterations' => 100, 'max_iterations' => 7 ], 'h:c:q' );
t_eq( [ $b[ 'post' ][ 'iterations_option' ], $b[ 'post' ][ 'mc_iterations' ], $b[ 'post' ][ 'tinoise_option' ] ], [ "0", "100", "0" ], 'MC: no iterations, mc 100, no noise fit' );
$b = pl_build_post( '2DSA', [ 'mc_iterations' => 100, 'grid' => [ 'type' => 'custom', 'modelID' => 4711 ] ], 'h:c:q' );
t_eq( [ $b[ 'page' ], $b[ 'post' ][ 'CG_modelID' ], $b[ 'post' ][ 'mc_iterations' ] ], [ '2DSA-CG_1.php', "4711", "1" ], 'custom grid page, mc forced 1 on non-MC' );
$b = pl_build_post( 'PCSA', [ 'curve_type' => 'SL', 'pcsa_solute_type' => '023.swv', 'x_max' => 5, 's_value_max' => 99, 'noise_fit' => 'ri' ], 'h:c:q' );
t_eq( [ $b[ 'page' ], $b[ 'post' ][ 'curve_type' ], $b[ 'post' ][ 'solute_type' ], $b[ 'post' ][ 'x_max' ] ], [ 'PCSA_1.php', 'SL', '023.swv', "5" ], 'PCSA params' );
t_ok( !isset( $b[ 'post' ][ 's_value_max' ] ), 'PCSA ignores 2DSA keys' );
t_eq( [ $b[ 'post' ][ 'tinoise_option' ], $b[ 'post' ][ 'rinoise_option' ] ], [ "0", "1" ], 'PCSA RI noise' );
t_throws( function() { pl_build_post( 'PCSA', [ 'pcsa_solute_type' => 'x' ], 'c' ); }, 'unknown solute type throws' );

## ---------------------------------------------------------------- transitions

t_section( 'scheduler transitions' );
$rows = [
    1 => [ 'status' => 'FINISHED', 'after' => [] ],
    2 => [ 'status' => 'PENDING',  'after' => [ 1 ] ],
    3 => [ 'status' => 'PENDING',  'after' => [ 1 ] ],
    4 => [ 'status' => 'PENDING',  'after' => [ 2 ] ],
];
$t = pl_compute_transitions( $rows );
t_eq( $t, [ 'blocked' => [], 'unblocked' => [], 'ready' => [ 2, 3 ] ], 'independent stages become ready together' );
$t = pl_compute_transitions( $rows, 1 );
t_eq( $t[ 'ready' ], [ 2 ], 'throttle max_active=1' );
$rows[ 9 ] = [ 'status' => 'RUNNING', 'after' => [] ];
t_eq( pl_compute_transitions( $rows, 2 )[ 'ready' ], [ 2 ], 'throttle counts running rows' );
unset( $rows[ 9 ] );

$rows = [
    1 => [ 'status' => 'RUN_TIMEOUT', 'after' => [] ],
    2 => [ 'status' => 'PENDING', 'after' => [ 1 ] ],
    3 => [ 'status' => 'PENDING', 'after' => [ 2 ] ],
    4 => [ 'status' => 'FINISHED', 'after' => [] ],
    5 => [ 'status' => 'PENDING', 'after' => [ 4, 1 ] ],      # group stage: one member failed
    6 => [ 'status' => 'PENDING', 'after' => [ 4 ] ],
];
$t = pl_compute_transitions( $rows );
t_eq( $t[ 'blocked' ], [ 2, 3, 5 ], 'failure blocks transitive successors and the group stage' );
t_eq( $t[ 'ready' ], [ 6 ], 'independent stage of the other member continues' );

$rows = [
    1 => [ 'status' => 'PENDING', 'after' => [] ],     # retried
    2 => [ 'status' => 'BLOCKED', 'after' => [ 1 ] ],
    3 => [ 'status' => 'BLOCKED', 'after' => [ 2 ] ],
];
$t = pl_compute_transitions( $rows );
t_eq( [ $t[ 'unblocked' ], $t[ 'ready' ] ], [ [ 2, 3 ], [ 1 ] ], 'retry unblocks successors' );
t_eq( pl_compute_transitions( [ 1 => [ 'status' => 'COMPLETE', 'after' => [] ], 2 => [ 'status' => 'PENDING', 'after' => [ 1 ] ] ] )[ 'ready' ],
      [], 'COMPLETE (outputs not yet recorded) does not release successors' );

t_section( 'pipeline action' );
t_eq( pl_pipeline_action( 'ACTIVE', [ [ 'status' => 'FINISHED' ], [ 'status' => 'SKIPPED' ] ] ), 'finish', 'all finished -> finish' );
t_eq( pl_pipeline_action( 'ACTIVE', [ [ 'status' => 'FINISHED' ], [ 'status' => 'FAILED' ] ] ), null, 'failure keeps pipeline active' );
t_eq( pl_pipeline_action( 'CANCELED', [ [ 'status' => 'CANCELED', 'currentGfacID' => 'x' ] ] ), null, 'canceled with job still attached' );
t_eq( pl_pipeline_action( 'CANCELED', [ [ 'status' => 'CANCELED', 'currentGfacID' => null ], [ 'status' => 'FINISHED' ] ] ), 'archive', 'canceled -> archive' );

t_done();
