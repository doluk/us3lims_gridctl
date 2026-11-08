<?php
/*
 * pipeline_lib.php
 *
 * Analysis pipeline: multi-stage 2DSA / PCSA jobs (per dataset and global
 * over user-defined groups) submitted from the LIMS queue setup.
 * Design: doc/analysis_pipeline_design.md
 *
 * Every enabled stage of every dataset / group is one row in
 * <lims_db>.autoflowAnalysis (pipelineID IS NOT NULL). Rows depend on each
 * other only through explicit predecessor lists (statusJson.after); stages
 * without a path between them run in parallel.
 *
 * Sections:
 *   1. catalogue and constants
 *   2. spec validation and expansion (pure)
 *   3. parameter and input resolution (pure)
 *   4. LIMS form POST builder (pure)
 *   5. scheduler transitions (pure)
 *   6. database layer (create pipeline, scheduler pass, outputs)
 *
 * The pure functions have no side effects and are unit tested in
 * autoflow_util/tests/pipeline_lib_test.php.
 */

if ( defined( 'PIPELINE_LIB_LOADED' ) ) {
    return;
}
define( 'PIPELINE_LIB_LOADED', 1 );

## ------------------------------------------------------------------------
## 1. catalogue and constants
## ------------------------------------------------------------------------

define( 'PL_SPEC_VERSION', 2 );
define( 'PL_SENTINEL_GUID', '00000000-0000-0000-0000-000000000000' );
define( 'PL_SENTINEL_AUTOFLOWID', 0 );

## family: LIMS form family; scope: dataset|group; noise_fit: may fit noise;
## conditioning: the stage every other stage of a dataset must follow or precede (V2)
function pl_methods() {
    return [
        '2DSA'       => [ 'family' => '2dsa', 'scope' => 'dataset', 'noise_fit' => true,  'conditioning' => 0 ],
        '2DSA_IT'    => [ 'family' => '2dsa', 'scope' => 'dataset', 'noise_fit' => true,  'conditioning' => 1 ],
        '2DSA_MC'    => [ 'family' => '2dsa', 'scope' => 'dataset', 'noise_fit' => true,  'conditioning' => 0 ],
        'PCSA'       => [ 'family' => 'pcsa', 'scope' => 'dataset', 'noise_fit' => true,  'conditioning' => 0 ],
        'PCSA_MC'    => [ 'family' => 'pcsa', 'scope' => 'dataset', 'noise_fit' => true,  'conditioning' => 0 ],
        '2DSA_GL'    => [ 'family' => '2dsa', 'scope' => 'group',   'noise_fit' => false, 'conditioning' => 0 ],
        '2DSA_GL_IT' => [ 'family' => '2dsa', 'scope' => 'group',   'noise_fit' => false, 'conditioning' => 0 ],
        '2DSA_GL_MC' => [ 'family' => '2dsa', 'scope' => 'group',   'noise_fit' => false, 'conditioning' => 0 ],
        'PCSA_GL'    => [ 'family' => 'pcsa', 'scope' => 'group',   'noise_fit' => false, 'conditioning' => 0 ],
        'PCSA_GL_MC' => [ 'family' => 'pcsa', 'scope' => 'group',   'noise_fit' => false, 'conditioning' => 0 ],
    ];
}

## per method: iterations_option (2DSA family) and whether MC iterations are required (> 1)
function pl_method_mode( $method ) {
    $it = in_array( $method, [ '2DSA_IT', '2DSA_GL_IT' ] );
    $mc = in_array( $method, [ '2DSA_MC', 'PCSA_MC', '2DSA_GL_MC', 'PCSA_GL_MC' ] );
    return [ 'iterate' => $it, 'mc' => $mc ];
}

## defaults of the LIMS forms (same values as the GMP path in submitone.php)
function pl_defaults_2dsa() {
    return [
        "s_value_min"        => "1",
        "s_value_max"        => "10",
        "s_grid_points"      => "64",
        "ff0_min"            => "1",
        "ff0_max"            => "4",
        "ff0_grid_points"    => "64",
        "mc_iterations"      => "1",
        "tinoise_option"     => "0",
        "rinoise_option"     => "0",
        "fit_mb_select"      => "0",
        "meniscus_range"     => "0.03",
        "meniscus_points"    => "11",
        "iterations_option"  => "0",
        "max_iterations"     => "10",
        "debug_level-value"  => "0",
        "debug_text-value"   => "",
        "simpoints-value"    => "200",
        "band_volume-value"  => "0.015",
        "radial_grid"        => "0",
        "time_grid"          => "1",
        "uniform_grid"       => "6",
        "TIGRE"              => "Submit",
    ];
}

function pl_defaults_pcsa() {
    return [
        "curve_type"           => "IS",
        "solute_type"          => "013.skv",
        "x_min"                => "1",
        "x_max"                => "10",
        "y_min"                => "1",
        "y_max"                => "4",
        "z_value"              => "0",
        "vars_count"           => "10",
        "hl_vars_count"        => "100",
        "gfit_iterations"      => "3",
        "thr_deltr_ratio"      => "0.0001",
        "curves_points"        => "200",
        "tikreg_option"        => "0",
        "tikreg_alpha"         => "0.275",
        "mc_iterations"        => "1",
        "tinoise_option"       => "0",
        "rinoise_option"       => "0",
        "debug_level-value"    => "0",
        "debug_text-value"     => "",
        "simpoints-value"      => "200",
        "band_volume-value"    => "0.015",
        "radial_grid"          => "0",
        "time_grid"            => "1",
        "TIGRE"                => "Submit",
    ];
}

## parameter keys accepted in spec params (anything else is a validation error)
## value: family it applies to ('2dsa', 'pcsa', 'all')
function pl_param_keys() {
    return [
        ## 2DSA
        's_value_min' => '2dsa', 's_value_max' => '2dsa', 's_grid_points' => '2dsa',
        'ff0_min' => '2dsa', 'ff0_max' => '2dsa', 'ff0_grid_points' => '2dsa',
        'uniform_grid' => '2dsa', 'max_iterations' => '2dsa', 'grid' => '2dsa',
        ## PCSA
        'curve_type' => 'pcsa', 'pcsa_solute_type' => 'pcsa',
        'x_min' => 'pcsa', 'x_max' => 'pcsa', 'y_min' => 'pcsa', 'y_max' => 'pcsa', 'z_value' => 'pcsa',
        'vars_count' => 'pcsa', 'hl_vars_count' => 'pcsa', 'gfit_iterations' => 'pcsa',
        'thr_deltr_ratio' => 'pcsa', 'curves_points' => 'pcsa',
        'tikreg_option' => 'pcsa', 'tikreg_alpha' => 'pcsa',
        ## both
        'mc_iterations' => 'all', 'noise_fit' => 'all',
        'simpoints-value' => 'all', 'band_volume-value' => 'all', 'radial_grid' => 'all', 'time_grid' => 'all',
        'debug_level-value' => 'all', 'debug_text-value' => 'all',
    ];
}

## parameters the LIMS reads per dataset in a global fit (payload_manager::acquirePostedData);
## all other parameters are job-level (taken from dataset 0 only)
function pl_dataset_level_keys() {
    return [ 'simpoints-value', 'band_volume-value', 'radial_grid', 'time_grid' ];
}

function pl_noise_fit_values() {
    return [ 'none', 'ti', 'ri', 'ti+ri' ];
}

function pl_pcsa_curve_types() {
    return [ "SL", "IS", "DS", "All", "HL", "2O" ];
}

## row status classes (lower case; gridctl writes upper case)
function pl_status_class( $status ) {
    $s = strtolower( trim( (string) $status ) );
    switch ( $s ) {
        case 'pending':
            return 'pending';
        case 'blocked':
            return 'blocked';
        case 'finished':
            return 'finished';
        case 'skipped':
            return 'skipped';
        case 'complete':
        case 'done':
            return 'complete';      # job done, outputs not yet recorded
        case 'canceled':
        case 'cancelled':
            return 'canceled';
        case 'failed':
        case 'error':
        case 'submit_timeout':
        case 'run_timeout':
        case 'data_timeout':
        case 'failed_data':
            return 'failed';
        default:
            return 'active';        # ready, submitted, running, data, ...
    }
}

## ------------------------------------------------------------------------
## 2. spec validation and expansion
## ------------------------------------------------------------------------

function pl_row_key_dataset( $datasetKey, $stageId ) {
    return "d|$datasetKey|$stageId";
}

function pl_row_key_group( $groupName, $stageId ) {
    return "g|$groupName|$stageId";
}

## index spec.requests by key
function pl_requests_by_key( $spec ) {
    $out = [];
    foreach ( $spec[ 'requests' ] ?? [] as $r ) {
        if ( isset( $r[ 'key' ] ) ) {
            $out[ (string) $r[ 'key' ] ] = $r;
        }
    }
    return $out;
}

## stage enabled for a dataset (dataset override wins)
function pl_dataset_stage_enabled( $spec, $datasetKey, $stage ) {
    $ov = $spec[ 'datasets' ][ $datasetKey ][ 'stages' ][ $stage[ 'id' ] ] ?? [];
    if ( array_key_exists( 'enabled', $ov ) ) {
        return (bool) $ov[ 'enabled' ];
    }
    return (bool) ( $stage[ 'enabled' ] ?? true );
}

## predecessors after removing disabled stages: a disabled predecessor is
## replaced by its own (effective) predecessors
function pl_effective_after( $stagesById, $enabled, $id, &$visiting = [] ) {
    $out = [];
    if ( isset( $visiting[ $id ] ) ) {
        return $out;    # cycle, reported by validation
    }
    $visiting[ $id ] = true;
    foreach ( $stagesById[ $id ][ 'after' ] ?? [] as $p ) {
        if ( !isset( $stagesById[ $p ] ) ) {
            continue;   # unknown id, reported by validation
        }
        if ( !empty( $enabled[ $p ] ) ) {
            $out[ $p ] = true;
        } else {
            foreach ( pl_effective_after( $stagesById, $enabled, $p, $visiting ) as $pp ) {
                $out[ $pp ] = true;
            }
        }
    }
    unset( $visiting[ $id ] );
    return array_keys( $out );
}

/*
 * Resolve parameters of one expanded row.
 *  dataset row: L0 spec.params, L1 stage.params, L2 datasets[key].params, L3 datasets[key].stages[id].params
 *  group row:   job-level  L0, L1 group-stage.params, L2 group.params, L3 group.stages[i].params (same object as L1)
 *               dataset-level per member: L0 + datasets[key].params, restricted to pl_dataset_level_keys()
 * Returns flat array (dataset row) or [ 'job' => [...], 'datasets' => [ key => [...] ] ] (group row).
 */
function pl_resolve_params( $spec, $row ) {
    $l0 = $spec[ 'params' ] ?? [];
    if ( $row[ 'rowKind' ] == 'triple' ) {
        $stage = pl_find_template_stage( $spec, $row[ 'stageKey' ] );
        $ds    = $spec[ 'datasets' ][ $row[ 'datasetKey' ] ] ?? [];
        return array_replace(
            $l0,
            $stage[ 'params' ] ?? [],
            $ds[ 'params' ] ?? [],
            $ds[ 'stages' ][ $row[ 'stageKey' ] ][ 'params' ] ?? []
        );
    }
    $group  = pl_find_group( $spec, $row[ 'groupName' ] );
    $gstage = pl_find_group_stage( $group, $row[ 'stageKey' ] );
    $job    = array_replace( $l0, $group[ 'params' ] ?? [], $gstage[ 'params' ] ?? [] );
    $job[ 'noise_fit' ] = 'none';   # global fits cannot fit noise (D9)
    $dsl    = array_flip( pl_dataset_level_keys() );
    $datasets = [];
    foreach ( $group[ 'members' ] as $m ) {
        $p = array_replace( $l0, $spec[ 'datasets' ][ $m ][ 'params' ] ?? [] );
        $datasets[ $m ] = array_intersect_key( $p, $dsl );
    }
    return [ 'job' => $job, 'datasets' => $datasets ];
}

function pl_find_template_stage( $spec, $id ) {
    foreach ( $spec[ 'stages' ] ?? [] as $s ) {
        if ( ( $s[ 'id' ] ?? null ) === $id ) {
            return $s;
        }
    }
    return null;
}

function pl_find_group( $spec, $name ) {
    foreach ( $spec[ 'groups' ] ?? [] as $g ) {
        if ( ( $g[ 'name' ] ?? null ) === $name ) {
            return $g;
        }
    }
    return null;
}

function pl_find_group_stage( $group, $id ) {
    foreach ( $group[ 'stages' ] ?? [] as $s ) {
        if ( ( $s[ 'id' ] ?? null ) === $id ) {
            return $s;
        }
    }
    return null;
}

## resolved noise_fit of a dataset row ('none' if not fitting noise)
function pl_row_fits_noise( $spec, $row ) {
    $methods = pl_methods();
    if ( !$methods[ $row[ 'method' ] ][ 'noise_fit' ] ) {
        return false;
    }
    $p = pl_resolve_params( $spec, $row );
    $nf = $p[ 'noise_fit' ] ?? 'none';
    return $nf !== 'none';
}

/*
 * Validate a pipeline spec and expand it into rows.
 * Returns [ 'errors' => [string...], 'rows' => [ rowKey => row ] ] with rows in
 * topological order (predecessors first). rows is empty if there are errors.
 *
 * row = [
 *   'key'        => row key,
 *   'rowKind'    => 'triple' | 'group',
 *   'datasetKey' => dataset key (triple rows),
 *   'groupName'  => group name (group rows),
 *   'members'    => [ dataset keys ] (group rows),
 *   'stageKey'   => stage id,
 *   'method'     => method,
 *   'after'      => [ row keys ],
 *   'noise_from' => 'queue_setup' | 'none' | [ 'row' => rowKey ] |
 *                   [ 'member_stage' => id, 'rows' => [ datasetKey => rowKey ] ],
 * ]
 */
function pl_expand_spec( $spec ) {
    $errors  = [];
    $methods = pl_methods();
    $pkeys   = pl_param_keys();

    if ( !is_array( $spec ) ) {
        return [ 'errors' => [ 'spec is not an object' ], 'rows' => [] ];
    }
    if ( ( $spec[ 'version' ] ?? null ) != PL_SPEC_VERSION ) {
        $errors[] = "spec version must be " . PL_SPEC_VERSION;
    }

    ## --- parameters ---
    $check_params = function( $params, $where ) use ( &$errors, $pkeys ) {
        if ( $params === null ) {
            return;
        }
        if ( !is_array( $params ) ) {
            $errors[] = "$where: params must be an object";
            return;
        }
        foreach ( $params as $k => $v ) {
            if ( !isset( $pkeys[ $k ] ) ) {
                $errors[] = "$where: unknown parameter '$k'";
                continue;
            }
            if ( $k == 'noise_fit' && !in_array( $v, pl_noise_fit_values(), true ) ) {
                $errors[] = "$where: noise_fit must be one of " . implode( ', ', pl_noise_fit_values() );
            } else if ( $k == 'grid' ) {
                $t = is_array( $v ) ? ( $v[ 'type' ] ?? null ) : null;
                if ( $t === 'custom' ) {
                    if ( intval( $v[ 'modelID' ] ?? 0 ) <= 0 ) {
                        $errors[] = "$where: custom grid requires a modelID";
                    }
                } else if ( $t !== 'uniform' ) {
                    $errors[] = "$where: grid.type must be 'uniform' or 'custom'";
                }
            } else if ( $k == 'curve_type' && !in_array( $v, pl_pcsa_curve_types(), true ) ) {
                $errors[] = "$where: unsupported PCSA curve_type '$v'";
            } else if ( $k != 'grid' && !is_scalar( $v ) ) {
                $errors[] = "$where: parameter '$k' must be a scalar";
            }
        }
    };

    $check_params( $spec[ 'params' ] ?? null, "params" );

    ## --- requests (datasets) ---
    $requests = [];
    foreach ( $spec[ 'requests' ] ?? [] as $i => $r ) {
        $key = isset( $r[ 'key' ] ) ? (string) $r[ 'key' ] : '';
        if ( $key === '' || strpos( $key, '|' ) !== false ) {
            $errors[] = "requests[$i]: key missing or contains '|'";
            continue;
        }
        if ( isset( $requests[ $key ] ) ) {
            $errors[] = "requests: duplicate key '$key'";
            continue;
        }
        foreach ( [ 'rawDataID', 'editedDataID' ] as $f ) {
            if ( intval( $r[ $f ] ?? 0 ) <= 0 ) {
                $errors[] = "requests[$key]: $f missing";
            }
        }
        $requests[ $key ] = $r;
    }
    if ( !count( $requests ) ) {
        $errors[] = "no datasets (requests) in spec";
    }

    ## --- stage template ---
    $stagesById = [];
    foreach ( $spec[ 'stages' ] ?? [] as $i => $s ) {
        $id = isset( $s[ 'id' ] ) ? (string) $s[ 'id' ] : '';
        if ( !preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $id ) ) {
            $errors[] = "stages[$i]: id missing or invalid (1-32 of A-Z a-z 0-9 _ -)";
            continue;
        }
        if ( isset( $stagesById[ $id ] ) ) {
            $errors[] = "stages: duplicate id '$id'";
            continue;
        }
        $m = $s[ 'method' ] ?? '';
        if ( !isset( $methods[ $m ] ) || $methods[ $m ][ 'scope' ] != 'dataset' ) {
            $errors[] = "stage '$id': method '$m' is not a dataset method";
            continue;
        }
        $check_params( $s[ 'params' ] ?? null, "stage '$id'" );
        $stagesById[ $id ] = $s;
    }
    foreach ( $stagesById as $id => $s ) {
        foreach ( $s[ 'after' ] ?? [] as $p ) {
            if ( !isset( $stagesById[ $p ] ) ) {
                $errors[] = "stage '$id': after references unknown stage '$p'";
            }
        }
    }

    ## --- dataset overrides ---
    foreach ( $spec[ 'datasets' ] ?? [] as $key => $ds ) {
        if ( !isset( $requests[ (string) $key ] ) ) {
            $errors[] = "datasets: override for unknown dataset '$key'";
            continue;
        }
        $check_params( $ds[ 'params' ] ?? null, "dataset '$key'" );
        foreach ( $ds[ 'stages' ] ?? [] as $sid => $ov ) {
            if ( !isset( $stagesById[ $sid ] ) ) {
                $errors[] = "dataset '$key': override for unknown stage '$sid'";
                continue;
            }
            $check_params( $ov[ 'params' ] ?? null, "dataset '$key' stage '$sid'" );
        }
    }

    if ( count( $errors ) ) {
        return [ 'errors' => $errors, 'rows' => [] ];
    }

    ## --- expand dataset rows ---
    $rows = [];
    foreach ( $requests as $key => $r ) {
        $enabled = [];
        foreach ( $stagesById as $id => $s ) {
            $enabled[ $id ] = pl_dataset_stage_enabled( $spec, $key, $s );
        }
        foreach ( $stagesById as $id => $s ) {
            if ( !$enabled[ $id ] ) {
                continue;
            }
            $after = [];
            foreach ( pl_effective_after( $stagesById, $enabled, $id ) as $p ) {
                $after[] = pl_row_key_dataset( $key, $p );
            }
            $nf = $spec[ 'datasets' ][ $key ][ 'stages' ][ $id ][ 'noise_from' ] ?? ( $s[ 'noise_from' ] ?? null );
            $rk = pl_row_key_dataset( $key, $id );
            $rows[ $rk ] = [
                'key'        => $rk,
                'rowKind'    => 'triple',
                'datasetKey' => $key,
                'stageKey'   => $id,
                'method'     => $s[ 'method' ],
                'after'      => $after,
                'noise_from_spec' => $nf,
            ];
        }
    }

    ## --- groups ---
    $groupNames = [];
    foreach ( $spec[ 'groups' ] ?? [] as $gi => $g ) {
        $gname = isset( $g[ 'name' ] ) ? (string) $g[ 'name' ] : '';
        if ( !preg_match( '/^[^|]{1,80}$/', $gname ) ) {
            $errors[] = "groups[$gi]: name missing, too long or contains '|'";
            continue;
        }
        if ( isset( $groupNames[ $gname ] ) ) {
            $errors[] = "groups: duplicate name '$gname'";
            continue;
        }
        $groupNames[ $gname ] = true;
        $members = array_values( array_unique( array_map( 'strval', $g[ 'members' ] ?? [] ) ) );
        if ( count( $members ) < 2 ) {
            $errors[] = "group '$gname': needs at least 2 members (V6)";
        }
        foreach ( $members as $m ) {
            if ( !isset( $requests[ $m ] ) ) {
                $errors[] = "group '$gname': unknown member '$m'";
            }
        }
        $check_params( $g[ 'params' ] ?? null, "group '$gname'" );
        if ( ( $g[ 'params' ][ 'noise_fit' ] ?? 'none' ) !== 'none' ) {
            $errors[] = "group '$gname': global fits cannot fit noise (noise_fit must be 'none')";
        }

        $gstages = [];
        foreach ( $g[ 'stages' ] ?? [] as $si => $gs ) {
            $id = isset( $gs[ 'id' ] ) ? (string) $gs[ 'id' ] : '';
            if ( !preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $id ) ) {
                $errors[] = "group '$gname' stages[$si]: id missing or invalid";
                continue;
            }
            if ( isset( $gstages[ $id ] ) ) {
                $errors[] = "group '$gname': duplicate stage id '$id'";
                continue;
            }
            $m = $gs[ 'method' ] ?? '';
            if ( !isset( $methods[ $m ] ) || $methods[ $m ][ 'scope' ] != 'group' ) {
                $errors[] = "group '$gname' stage '$id': method '$m' is not a group method";
                continue;
            }
            $check_params( $gs[ 'params' ] ?? null, "group '$gname' stage '$id'" );
            if ( ( $gs[ 'params' ][ 'noise_fit' ] ?? 'none' ) !== 'none' ) {
                $errors[] = "group '$gname' stage '$id': global fits cannot fit noise (noise_fit must be 'none')";
            }
            if ( !count( $gs[ 'after_member' ] ?? [] ) ) {
                $errors[] = "group '$gname' stage '$id': after_member must name at least one dataset stage";
            }
            foreach ( $gs[ 'after_member' ] ?? [] as $am ) {
                if ( !isset( $stagesById[ $am ] ) ) {
                    $errors[] = "group '$gname' stage '$id': after_member references unknown stage '$am'";
                }
            }
            $gstages[ $id ] = $gs;
        }
        foreach ( $gstages as $id => $gs ) {
            foreach ( $gs[ 'after' ] ?? [] as $p ) {
                if ( !isset( $gstages[ $p ] ) ) {
                    $errors[] = "group '$gname' stage '$id': after references unknown group stage '$p'";
                }
            }
        }
        if ( count( $errors ) ) {
            continue;
        }

        $genabled = [];
        foreach ( $gstages as $id => $gs ) {
            $genabled[ $id ] = (bool) ( $gs[ 'enabled' ] ?? true );
        }
        foreach ( $gstages as $id => $gs ) {
            if ( !$genabled[ $id ] ) {
                continue;
            }
            $after = [];
            foreach ( $gs[ 'after_member' ] as $am ) {
                foreach ( $members as $m ) {
                    $mk = pl_row_key_dataset( $m, $am );
                    if ( !isset( $rows[ $mk ] ) ) {
                        $errors[] = "group '$gname' stage '$id': stage '$am' is disabled for member '$m' (V4)";
                        continue;
                    }
                    $after[] = $mk;
                }
            }
            foreach ( pl_effective_after( $gstages, $genabled, $id ) as $p ) {
                $after[] = pl_row_key_group( $gname, $p );
            }
            $rk = pl_row_key_group( $gname, $id );
            $rows[ $rk ] = [
                'key'        => $rk,
                'rowKind'    => 'group',
                'groupName'  => $gname,
                'members'    => $members,
                'stageKey'   => $id,
                'method'     => $gs[ 'method' ],
                'after'      => array_values( array_unique( $after ) ),
                'after_member' => array_values( $gs[ 'after_member' ] ),
                'noise_from_spec' => $gs[ 'noise_from' ] ?? null,
            ];
        }
    }

    if ( count( $errors ) ) {
        return [ 'errors' => $errors, 'rows' => [] ];
    }
    if ( !count( $rows ) ) {
        return [ 'errors' => [ 'no enabled stages' ], 'rows' => [] ];
    }

    ## --- V1: acyclic, topological order ---
    $sorted = pl_topo_sort( $rows, $cycle );
    if ( $sorted === null ) {
        return [ 'errors' => [ "dependency cycle between stages: " . implode( ', ', $cycle ) . " (V1)" ], 'rows' => [] ];
    }
    $rows = $sorted;

    ## ancestors per row
    $anc = [];
    foreach ( $rows as $k => $r ) {
        $a = [];
        foreach ( $r[ 'after' ] as $p ) {
            $a[ $p ] = true;
            $a += $anc[ $p ];
        }
        $anc[ $k ] = $a;
    }

    ## --- V2: 2DSA_IT first ---
    ## At most one 2DSA_IT stage per dataset; every other stage of the dataset must either
    ## follow it or precede it (e.g. a 2DSA pre-fit). A plain 2DSA may thus be a pre-fit or a
    ## later consumer (e.g. with a custom grid). Group stages: see V8.
    foreach ( $requests as $key => $r ) {
        $it = null;
        foreach ( $rows as $k => $row ) {
            if ( $row[ 'rowKind' ] == 'triple' && $row[ 'datasetKey' ] == $key && $row[ 'method' ] == '2DSA_IT' ) {
                if ( $it !== null ) {
                    $errors[] = "dataset '$key': more than one enabled 2DSA_IT stage (V2)";
                }
                $it = $k;
            }
        }
        if ( $it === null ) {
            continue;
        }
        foreach ( $rows as $k => $row ) {
            if ( $k == $it || $row[ 'rowKind' ] != 'triple' || $row[ 'datasetKey' ] != $key ) {
                continue;
            }
            if ( !isset( $anc[ $k ][ $it ] ) && !isset( $anc[ $it ][ $k ] ) ) {
                $errors[] = "dataset '$key': stage '{$row['stageKey']}' must run after '{$rows[$it]['stageKey']}' (2DSA_IT) or before it (V2)";
            }
        }
    }

    ## --- V8: global fits need a 2DSA-IT model of every member ---
    ## The LIMS scales global fits by each dataset's total concentration, taken from the
    ## latest 2DSA-IT model of the edit (payload_manager::model_concentration); without it
    ## file_writer returns "2DSA-IT-MISSING". So each group stage must follow the 2DSA_IT
    ## stage of every member.
    foreach ( $rows as $k => $row ) {
        if ( $row[ 'rowKind' ] != 'group' ) {
            continue;
        }
        foreach ( $row[ 'members' ] as $m ) {
            $found = false;
            foreach ( $rows as $kk => $rr ) {
                if ( $rr[ 'rowKind' ] == 'triple' && $rr[ 'datasetKey' ] == $m && $rr[ 'method' ] == '2DSA_IT'
                     && isset( $anc[ $k ][ $kk ] ) ) {
                    $found = true;
                }
            }
            if ( !$found ) {
                $errors[] = "group '{$row['groupName']}' stage '{$row['stageKey']}': member '$m' has no preceding 2DSA_IT stage; " .
                            "global fits need each dataset's 2DSA-IT model (V8)";
            }
        }
    }

    ## --- V5: noise sources ---
    foreach ( $rows as $k => &$row ) {
        $nf = $row[ 'noise_from_spec' ];
        unset( $row[ 'noise_from_spec' ] );
        if ( $row[ 'rowKind' ] == 'triple' ) {
            if ( $nf === null ) {
                ## default: nearest predecessor fitting noise (breadth first), else queue setup selection
                $nf = 'queue_setup';
                $queue = $row[ 'after' ];
                $seen  = [];
                while ( count( $queue ) ) {
                    $p = array_shift( $queue );
                    if ( isset( $seen[ $p ] ) ) {
                        continue;
                    }
                    $seen[ $p ] = true;
                    if ( pl_row_fits_noise( $spec, $rows[ $p ] ) ) {
                        $nf = $rows[ $p ][ 'stageKey' ];
                        break;
                    }
                    $queue = array_merge( $queue, $rows[ $p ][ 'after' ] );
                }
            }
            if ( $nf === 'queue_setup' || $nf === 'none' ) {
                $row[ 'noise_from' ] = $nf;
                continue;
            }
            $src = pl_row_key_dataset( $row[ 'datasetKey' ], (string) $nf );
            if ( !isset( $rows[ $src ] ) || !isset( $anc[ $k ][ $src ] ) ) {
                $errors[] = "dataset '{$row['datasetKey']}' stage '{$row['stageKey']}': noise source '$nf' is not a preceding stage (V5)";
                continue;
            }
            if ( !pl_row_fits_noise( $spec, $rows[ $src ] ) ) {
                $errors[] = "dataset '{$row['datasetKey']}' stage '{$row['stageKey']}': noise source '$nf' does not fit noise (V5)";
                continue;
            }
            $row[ 'noise_from' ] = [ 'row' => $src ];
        } else {
            if ( $nf === null ) {
                $nf = 'queue_setup';
                foreach ( $row[ 'after_member' ] as $am ) {
                    $all = true;
                    foreach ( $row[ 'members' ] as $m ) {
                        if ( !pl_row_fits_noise( $spec, $rows[ pl_row_key_dataset( $m, $am ) ] ) ) {
                            $all = false;
                        }
                    }
                    if ( $all ) {
                        $nf = "member:$am";
                        break;
                    }
                }
            }
            if ( $nf === 'queue_setup' || $nf === 'none' ) {
                $row[ 'noise_from' ] = $nf;
                continue;
            }
            if ( !preg_match( '/^member:(.+)$/', (string) $nf, $mm ) || !in_array( $mm[ 1 ], $row[ 'after_member' ], true ) ) {
                $errors[] = "group '{$row['groupName']}' stage '{$row['stageKey']}': noise source must be queue_setup, none or member:<stage in after_member> (V5)";
                continue;
            }
            $srcs = [];
            foreach ( $row[ 'members' ] as $m ) {
                $src = pl_row_key_dataset( $m, $mm[ 1 ] );
                if ( !pl_row_fits_noise( $spec, $rows[ $src ] ) ) {
                    $errors[] = "group '{$row['groupName']}' stage '{$row['stageKey']}': member '$m' stage '{$mm[1]}' does not fit noise (V5)";
                }
                $srcs[ $m ] = $src;
            }
            $row[ 'noise_from' ] = [ 'member_stage' => $mm[ 1 ], 'rows' => $srcs ];
        }
    }
    unset( $row );

    ## --- MC stages need mc_iterations > 1 ---
    foreach ( $rows as $k => $row ) {
        $mode = pl_method_mode( $row[ 'method' ] );
        $p = pl_resolve_params( $spec, $row );
        $mc = intval( $row[ 'rowKind' ] == 'group' ? ( $p[ 'job' ][ 'mc_iterations' ] ?? 1 ) : ( $p[ 'mc_iterations' ] ?? 1 ) );
        $where = $row[ 'rowKind' ] == 'group' ? "group '{$row['groupName']}'" : "dataset '{$row['datasetKey']}'";
        if ( $mode[ 'mc' ] && $mc < 2 ) {
            $errors[] = "$where stage '{$row['stageKey']}': {$row['method']} needs mc_iterations > 1";
        }
        if ( !$mode[ 'mc' ] && $mc > 1 ) {
            $errors[] = "$where stage '{$row['stageKey']}': mc_iterations > 1 requires an _MC method";
        }
    }

    if ( count( $errors ) ) {
        return [ 'errors' => $errors, 'rows' => [] ];
    }
    return [ 'errors' => [], 'rows' => $rows ];
}

## Kahn's algorithm, stable w.r.t. input order. Returns null on cycle ($cycle = involved keys).
function pl_topo_sort( $rows, &$cycle = null ) {
    $indeg = [];
    $succ  = [];
    foreach ( $rows as $k => $r ) {
        $indeg[ $k ] = 0;
        $succ[ $k ]  = [];
    }
    foreach ( $rows as $k => $r ) {
        foreach ( $r[ 'after' ] as $p ) {
            if ( !isset( $rows[ $p ] ) ) {
                continue;
            }
            $indeg[ $k ]++;
            $succ[ $p ][] = $k;
        }
    }
    $out   = [];
    $ready = [];
    foreach ( $rows as $k => $r ) {
        if ( $indeg[ $k ] == 0 ) {
            $ready[] = $k;
        }
    }
    while ( count( $ready ) ) {
        $k = array_shift( $ready );
        $out[ $k ] = $rows[ $k ];
        foreach ( $succ[ $k ] as $s ) {
            if ( --$indeg[ $s ] == 0 ) {
                $ready[] = $s;
            }
        }
    }
    if ( count( $out ) != count( $rows ) ) {
        $cycle = array_keys( array_diff_key( $rows, $out ) );
        return null;
    }
    $cycle = [];
    return $out;
}

## ------------------------------------------------------------------------
## 3. parameter and input resolution (at READY time)
## ------------------------------------------------------------------------

## latest noise per noiseType for an edit from recorded outputs
function pl_select_noise( $outputs, $editedDataID ) {
    $best = [];
    foreach ( $outputs[ 'noises' ] ?? [] as $n ) {
        if ( intval( $n[ 'editedDataID' ] ) != intval( $editedDataID ) ) {
            continue;
        }
        $t = $n[ 'noiseType' ] ?? 'ti_noise';
        if ( !isset( $best[ $t ] ) || intval( $n[ 'noiseID' ] ) > $best[ $t ] ) {
            $best[ $t ] = intval( $n[ 'noiseID' ] );
        }
    }
    ksort( $best );
    return array_values( $best );
}

/*
 * Inputs of a row (pinned when the row becomes READY).
 *  $sj        row statusJson (array) with noise_from in requestID form
 *  $rowsByID  requestID => statusJson of the pipeline's rows (for outputs)
 * Returns [ 'datasets' => [ [ 'key', 'editedDataID', 'noiseIDs' ], ... ] ] (one entry for triple rows),
 * or throws Exception if a noise source has no recorded outputs.
 */
function pl_resolve_inputs( $spec, $sj, $rowsByID ) {
    $requests = pl_requests_by_key( $spec );
    $keys = $sj[ 'rowKind' ] == 'group' ? $sj[ 'members' ] : [ $sj[ 'datasetKey' ] ];
    $nf   = $sj[ 'noise_from' ];
    $out  = [];
    foreach ( $keys as $key ) {
        if ( !isset( $requests[ $key ] ) ) {
            throw new Exception( "dataset '$key' not found in pipeline spec" );
        }
        $req = $requests[ $key ];
        $ed  = intval( $req[ 'editedDataID' ] );
        if ( $nf === 'none' ) {
            $noise = [];
        } else if ( $nf === 'queue_setup' ) {
            $noise = array_values( array_map( 'intval', array_filter( (array) ( $req[ 'noiseIDs' ] ?? [] ) ) ) );
        } else {
            $src = $sj[ 'rowKind' ] == 'group' ? ( $nf[ 'rows' ][ $key ] ?? null ) : ( $nf[ 'requestID' ] ?? null );
            if ( !$src || !isset( $rowsByID[ $src ] ) || !isset( $rowsByID[ $src ][ 'outputs' ] ) ) {
                throw new Exception( "noise source row $src of dataset '$key' has no recorded outputs" );
            }
            $noise = pl_select_noise( $rowsByID[ $src ][ 'outputs' ], $ed );
            if ( !count( $noise ) ) {
                throw new Exception( "noise source row $src produced no noise for dataset '$key' (editedDataID $ed)" );
            }
        }
        $out[] = [ 'key' => $key, 'editedDataID' => $ed, 'noiseIDs' => $noise ];
    }
    return [ 'datasets' => $out ];
}

## ------------------------------------------------------------------------
## 4. LIMS form POST builder
## ------------------------------------------------------------------------

function pl_pcsa_solute_types() {
    return [ "013.skv", "023.swv", "031.svk", "032.svw", "043.sdv", "103.ksv", "123.kwv", "132.kvw",
             "143.kdv", "203.wsv", "213.wkv", "231.wvk", "243.wdv", "301.vsk", "302.vsw", "312.vkv",
             "321.vwk", "341.vdv", "342.vdv", "403.dsv", "413.dkv", "423.dkv", "431.dvk", "432.dvw" ];
}

/*
 * Build the $_POST of the LIMS method page (2DSA_1.php, 2DSA-CG_1.php, PCSA_1.php)
 * for one dataset.
 *  $method   pipeline method
 *  $params   resolved params: flat (dataset row) or job-level merged with the dataset's
 *            dataset-level params (group row)
 *  $cluster  "host_name:cluster:queue"
 * Returns [ 'page' => '2DSA_1.php'|'2DSA-CG_1.php'|'PCSA_1.php', 'post' => [...] ].
 */
function pl_build_post( $method, $params, $cluster ) {
    $methods = pl_methods();
    if ( !isset( $methods[ $method ] ) ) {
        throw new Exception( "unknown method $method" );
    }
    $family = $methods[ $method ][ 'family' ];
    $mode   = pl_method_mode( $method );
    $nf     = $params[ 'noise_fit' ] ?? 'none';
    $ti     = in_array( $nf, [ 'ti', 'ti+ri' ] ) ? "1" : "0";
    $ri     = in_array( $nf, [ 'ri', 'ti+ri' ] ) ? "1" : "0";
    $common = [ 'simpoints-value', 'band_volume-value', 'radial_grid', 'time_grid',
                'debug_level-value', 'debug_text-value' ];

    if ( $family == 'pcsa' ) {
        $post = pl_defaults_pcsa();
        foreach ( [ 'curve_type', 'x_min', 'x_max', 'y_min', 'y_max', 'z_value', 'vars_count',
                    'hl_vars_count', 'gfit_iterations', 'thr_deltr_ratio', 'curves_points',
                    'tikreg_option', 'tikreg_alpha' ] as $k ) {
            if ( isset( $params[ $k ] ) ) {
                $post[ $k ] = (string) $params[ $k ];
            }
        }
        if ( isset( $params[ 'pcsa_solute_type' ] ) ) {
            if ( !in_array( $params[ 'pcsa_solute_type' ], pl_pcsa_solute_types(), true ) ) {
                throw new Exception( "unknown PCSA solute type {$params['pcsa_solute_type']}" );
            }
            $post[ 'solute_type' ] = $params[ 'pcsa_solute_type' ];
        }
        $page = 'PCSA_1.php';
    } else {
        $post = pl_defaults_2dsa();
        foreach ( [ 's_value_min', 's_value_max', 's_grid_points', 'ff0_min', 'ff0_max',
                    'ff0_grid_points', 'uniform_grid', 'max_iterations' ] as $k ) {
            if ( isset( $params[ $k ] ) ) {
                $post[ $k ] = (string) $params[ $k ];
            }
        }
        $post[ 'iterations_option' ] = $mode[ 'iterate' ] ? "1" : "0";
        $post[ 'fit_mb_select' ]     = "0";     # meniscus fitting is not part of the pipeline (D4)
        $page = '2DSA_1.php';
        $grid = $params[ 'grid' ] ?? [ 'type' => 'uniform' ];
        if ( ( $grid[ 'type' ] ?? 'uniform' ) == 'custom' ) {
            $post[ 'CG_modelID' ] = (string) intval( $grid[ 'modelID' ] );
            $page = '2DSA-CG_1.php';
        }
    }
    foreach ( $common as $k ) {
        if ( isset( $params[ $k ] ) ) {
            $post[ $k ] = (string) $params[ $k ];
        }
    }
    $post[ 'mc_iterations' ]  = $mode[ 'mc' ] ? (string) intval( $params[ 'mc_iterations' ] ?? 1 ) : "1";
    $post[ 'tinoise_option' ] = $ti;
    $post[ 'rinoise_option' ] = $ri;
    $post[ 'cluster' ]        = $cluster;
    return [ 'page' => $page, 'post' => $post ];
}

## payload manager class of a LIMS method page (us3lims_dbinst lib/payload_manager.php)
function pl_payload_class( $page ) {
    $map = [ '2DSA_1.php' => 'Payload_2DSA', '2DSA-CG_1.php' => 'Payload_2DSA_CG', 'PCSA_1.php' => 'Payload_PCSA' ];
    if ( !isset( $map[ $page ] ) ) {
        throw new Exception( "no payload class for page $page" );
    }
    return $map[ $page ];
}

/*
 * POSTs of a global (group) job, one per member dataset in $memberKeys order.
 * The LIMS reads job-level parameters from dataset 0 only and dataset-level parameters
 * from every dataset's POST (advanced review), so each POST is job-level params merged
 * with that member's dataset-level params.
 *  $params  resolved group params [ 'job' => [...], 'datasets' => [ key => [...] ] ]
 * Returns [ 'page' => ..., 'payload_class' => ..., 'posts' => [ post0, post1, ... ] ].
 */
function pl_build_group_posts( $method, $params, $memberKeys, $cluster ) {
    $methods = pl_methods();
    if ( !isset( $methods[ $method ] ) || $methods[ $method ][ 'scope' ] != 'group' ) {
        throw new Exception( "$method is not a group method" );
    }
    if ( count( $memberKeys ) < 2 ) {
        throw new Exception( "a global fit needs at least 2 datasets" );
    }
    $page  = null;
    $posts = [];
    foreach ( $memberKeys as $key ) {
        $p = array_replace( $params[ 'job' ], $params[ 'datasets' ][ $key ] ?? [] );
        $p[ 'noise_fit' ] = 'none';
        $b = pl_build_post( $method, $p, $cluster );
        if ( $page !== null && $b[ 'page' ] != $page ) {
            throw new Exception( "inconsistent LIMS page for group members" );
        }
        $page    = $b[ 'page' ];
        $posts[] = $b[ 'post' ];
    }
    return [ 'page' => $page, 'payload_class' => pl_payload_class( $page ), 'posts' => $posts ];
}

## $_SESSION['request'] entry for one dataset with pinned inputs
function pl_session_request( $spec, $input ) {
    $requests = pl_requests_by_key( $spec );
    $r = $requests[ $input[ 'key' ] ];
    return [
        'rawDataID'    => intval( $r[ 'rawDataID' ] ),
        'path'         => $r[ 'path' ] ?? '',
        'filename'     => $r[ 'filename' ] ?? '',
        'editedDataID' => intval( $input[ 'editedDataID' ] ),
        'editFilename' => $r[ 'editFilename' ] ?? '',
        'noiseIDs'     => $input[ 'noiseIDs' ],
        'editMeniscus' => $r[ 'editMeniscus' ] ?? '',
        'dataLeft'     => $r[ 'dataLeft' ] ?? '',
        'dataRight'    => $r[ 'dataRight' ] ?? '',
    ];
}

## ------------------------------------------------------------------------
## 5. scheduler transitions (pure)
## ------------------------------------------------------------------------

/*
 * $rows: requestID => [ 'status' => ..., 'after' => [ requestIDs ] ]
 * Returns [ 'blocked' => [ids], 'unblocked' => [ids], 'ready' => [ids] ]
 *  - PENDING rows with a failed/canceled/blocked predecessor become BLOCKED
 *  - BLOCKED rows whose predecessors are no longer failed/canceled/blocked become PENDING
 *  - PENDING rows whose predecessors are all FINISHED/SKIPPED become READY
 *    (at most $max_new_active, null = unlimited, counting rows already active)
 */
function pl_compute_transitions( $rows, $max_active = null ) {
    $class = [];
    foreach ( $rows as $id => $r ) {
        $class[ $id ] = pl_status_class( $r[ 'status' ] );
    }
    $blocked = [];
    $unblocked = [];
    do {
        $changed = false;
        foreach ( $rows as $id => $r ) {
            if ( $class[ $id ] != 'pending' && $class[ $id ] != 'blocked' ) {
                continue;
            }
            $bad = false;
            foreach ( $r[ 'after' ] as $p ) {
                $pc = $class[ $p ] ?? 'failed';     # missing predecessor = failed
                if ( in_array( $pc, [ 'failed', 'canceled', 'blocked' ] ) ) {
                    $bad = true;
                }
            }
            if ( $bad && $class[ $id ] == 'pending' ) {
                $class[ $id ] = 'blocked';
                $blocked[ $id ] = true;
                unset( $unblocked[ $id ] );
                $changed = true;
            } else if ( !$bad && $class[ $id ] == 'blocked' ) {
                $class[ $id ] = 'pending';
                $unblocked[ $id ] = true;
                unset( $blocked[ $id ] );
                $changed = true;
            }
        }
    } while ( $changed );

    $active = 0;
    foreach ( $class as $c ) {
        if ( $c == 'active' || $c == 'complete' ) {
            $active++;
        }
    }
    $ready = [];
    foreach ( $rows as $id => $r ) {
        if ( $class[ $id ] != 'pending' ) {
            continue;
        }
        $ok = true;
        foreach ( $r[ 'after' ] as $p ) {
            if ( !in_array( $class[ $p ] ?? 'failed', [ 'finished', 'skipped' ] ) ) {
                $ok = false;
            }
        }
        if ( !$ok ) {
            continue;
        }
        if ( $max_active !== null && $active >= $max_active ) {
            break;
        }
        $ready[] = $id;
        $active++;
    }
    return [ 'blocked' => array_keys( $blocked ), 'unblocked' => array_keys( $unblocked ), 'ready' => $ready ];
}

/*
 * What to do with a whole pipeline after the transitions.
 *  'finish'  ACTIVE pipeline, all rows FINISHED/SKIPPED -> FINISHED + archive
 *  'archive' CANCELED pipeline without running jobs     -> archive
 *  null      nothing
 */
function pl_pipeline_action( $pipelineStatus, $rows ) {
    $all_done = true;
    $running  = false;
    foreach ( $rows as $r ) {
        $c = pl_status_class( $r[ 'status' ] );
        if ( !in_array( $c, [ 'finished', 'skipped' ] ) ) {
            $all_done = false;
        }
        if ( in_array( $c, [ 'active', 'complete' ] ) || !empty( $r[ 'currentGfacID' ] ) ) {
            $running = true;
        }
    }
    if ( $pipelineStatus == 'ACTIVE' && $all_done ) {
        return 'finish';
    }
    if ( $pipelineStatus == 'CANCELED' && !$running ) {
        return 'archive';
    }
    return null;
}

## ------------------------------------------------------------------------
## 6. database layer
## ------------------------------------------------------------------------

class PipelineDbException extends Exception {}

## query with uniform error handling (works with and without mysqli exception mode)
function pl_query( $db, $sql ) {
    try {
        $res = mysqli_query( $db, $sql );
    } catch ( mysqli_sql_exception $e ) {
        throw new PipelineDbException( $e->getMessage() . " -- query: $sql" );
    }
    if ( $res === false ) {
        throw new PipelineDbException( mysqli_error( $db ) . " -- query: $sql" );
    }
    return $res;
}

function pl_esc( $db, $s ) {
    return mysqli_real_escape_string( $db, (string) $s );
}

function pl_ident( $name ) {
    if ( !preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
        throw new PipelineDbException( "invalid database name '$name'" );
    }
    return "`$name`";
}

function pl_json( $v ) {
    return json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
}

## does this LIMS db have the pipeline schema (us3_sql upgrades/2026-11-analysis_pipeline.sql)?
function pl_db_has_pipeline_schema( $db, $lims_db ) {
    $d = pl_esc( $db, $lims_db );
    $res = pl_query( $db, "SELECT COUNT(*) AS n FROM information_schema.COLUMNS " .
                          "WHERE TABLE_SCHEMA='$d' AND TABLE_NAME IN ('autoflowAnalysis','autoflowAnalysisHistory') " .
                          "AND COLUMN_NAME='pipelineID'" );
    $n1 = intval( mysqli_fetch_object( $res )->n );
    $res = pl_query( $db, "SELECT COUNT(*) AS n FROM information_schema.TABLES " .
                          "WHERE TABLE_SCHEMA='$d' AND TABLE_NAME='analysisPipeline'" );
    $n2 = intval( mysqli_fetch_object( $res )->n );
    return $n1 == 2 && $n2 == 1;
}

/*
 * Create a pipeline from a validated spec: analysisPipeline (SETUP), rows in
 * topological order (PENDING), then ACTIVE. Returns pipelineID.
 * Throws Exception with all validation errors on an invalid spec.
 */
function pl_create_pipeline( $db, $lims_db, $spec, $name, $invID, $cluster ) {
    $exp = pl_expand_spec( $spec );
    if ( count( $exp[ 'errors' ] ) ) {
        throw new Exception( "invalid pipeline:\n" . implode( "\n", $exp[ 'errors' ] ) );
    }
    $L = pl_ident( $lims_db );
    $requests = pl_requests_by_key( $spec );

    ## keep requestIDs unique across live + history (as set_autoflowAnalysis_auto_increment);
    ## ALTER TABLE commits implicitly, so it is done before the transaction
    $res  = pl_query( $db, "SELECT GREATEST( IFNULL( (SELECT MAX(requestID) FROM $L.autoflowAnalysisHistory), 0 ), " .
                           "IFNULL( (SELECT MAX(requestID) FROM $L.autoflowAnalysis), 0 ) ) + 1 AS n" );
    $next = intval( mysqli_fetch_object( $res )->n );
    pl_query( $db, "ALTER TABLE $L.autoflowAnalysis AUTO_INCREMENT = $next" );

    mysqli_begin_transaction( $db );
    try {
        pl_query( $db, "INSERT INTO $L.analysisPipeline SET " .
                       "name='" . pl_esc( $db, $name ) . "', invID=" . intval( $invID ) . ", " .
                       "clusterDefault='" . pl_esc( $db, $cluster ) . "', " .
                       "specJson='" . pl_esc( $db, pl_json( $spec ) ) . "', status='SETUP'" );
        $pipelineID = intval( mysqli_insert_id( $db ) );

        $ids = [];
        foreach ( $exp[ 'rows' ] as $k => $row ) {
            $after = array_map( function( $p ) use ( &$ids ) { return $ids[ $p ]; }, $row[ 'after' ] );
            $nf = $row[ 'noise_from' ];
            if ( is_array( $nf ) && isset( $nf[ 'row' ] ) ) {
                $nf = [ 'requestID' => $ids[ $nf[ 'row' ] ] ];
            } else if ( is_array( $nf ) && isset( $nf[ 'rows' ] ) ) {
                $nf[ 'rows' ] = array_map( function( $p ) use ( &$ids ) { return $ids[ $p ]; }, $nf[ 'rows' ] );
            }
            $sj = [
                'version'    => PL_SPEC_VERSION,
                'revision'   => 0,
                'rowKey'     => $k,
                'rowKind'    => $row[ 'rowKind' ],
                'stageKey'   => $row[ 'stageKey' ],
                'method'     => $row[ 'method' ],
                'after'      => $after,
                'noise_from' => $nf,
                'params'     => null,
                'inputs'     => null,
                'outputs'    => null,
                'processed'  => [],
            ];
            if ( $row[ 'rowKind' ] == 'group' ) {
                $sj[ 'groupName' ] = $row[ 'groupName' ];
                $sj[ 'members' ]   = $row[ 'members' ];
                $triple   = $row[ 'groupName' ];
                $filename = $requests[ $row[ 'members' ][ 0 ] ][ 'runID' ] ?? ( $requests[ $row[ 'members' ][ 0 ] ][ 'filename' ] ?? '' );
            } else {
                $sj[ 'datasetKey' ] = $row[ 'datasetKey' ];
                $req      = $requests[ $row[ 'datasetKey' ] ];
                $triple   = $req[ 'triple' ] ?? $row[ 'datasetKey' ];
                $filename = $req[ 'runID' ] ?? ( $req[ 'filename' ] ?? '' );
            }
            pl_query( $db, "INSERT INTO $L.autoflowAnalysis SET " .
                           "tripleName='" . pl_esc( $db, $triple ) . "', " .
                           "clusterDefault='" . pl_esc( $db, $cluster ) . "', " .
                           "filename='" . pl_esc( $db, $filename ) . "', " .
                           "aprofileGUID='" . PL_SENTINEL_GUID . "', " .
                           "invID=" . intval( $invID ) . ", " .
                           "statusJson='" . pl_esc( $db, pl_json( $sj ) ) . "', " .
                           "status='PENDING', statusMsg='Waiting for preceding stages', " .
                           "autoflowID=" . PL_SENTINEL_AUTOFLOWID . ", " .
                           "pipelineID=$pipelineID, " .
                           "rowKind='" . $row[ 'rowKind' ] . "', " .
                           "groupName=" . ( $row[ 'rowKind' ] == 'group' ? "'" . pl_esc( $db, $row[ 'groupName' ] ) . "'" : "NULL" ) . ", " .
                           "stageKey='" . pl_esc( $db, $row[ 'stageKey' ] ) . "'" );
            $ids[ $k ] = intval( mysqli_insert_id( $db ) );
        }
        pl_query( $db, "UPDATE $L.analysisPipeline SET status='ACTIVE' WHERE pipelineID=$pipelineID" );
        mysqli_commit( $db );
    } catch ( Exception $e ) {
        mysqli_rollback( $db );
        throw $e;
    }
    return $pipelineID;
}

## models and noises produced by an HPC analysis request
function pl_fetch_outputs( $db, $lims_db, $hpcRequestID ) {
    $L  = pl_ident( $lims_db );
    $id = intval( $hpcRequestID );
    $out = [ 'models' => [], 'noises' => [] ];
    if ( $id <= 0 ) {
        return $out;
    }
    $res = pl_query( $db, "SELECT m.modelID, m.editedDataID FROM $L.HPCAnalysisResult r " .
                          "JOIN $L.HPCAnalysisResultData d ON d.HPCAnalysisResultID=r.HPCAnalysisResultID AND d.HPCAnalysisResultType='model' " .
                          "JOIN $L.model m ON m.modelID=d.resultID WHERE r.HPCAnalysisRequestID=$id ORDER BY m.modelID" );
    while ( $o = mysqli_fetch_object( $res ) ) {
        $out[ 'models' ][] = [ 'modelID' => intval( $o->modelID ), 'editedDataID' => intval( $o->editedDataID ) ];
    }
    $res = pl_query( $db, "SELECT n.noiseID, n.editedDataID, n.noiseType FROM $L.HPCAnalysisResult r " .
                          "JOIN $L.HPCAnalysisResultData d ON d.HPCAnalysisResultID=r.HPCAnalysisResultID AND d.HPCAnalysisResultType='noise' " .
                          "JOIN $L.noise n ON n.noiseID=d.resultID WHERE r.HPCAnalysisRequestID=$id ORDER BY n.noiseID" );
    while ( $o = mysqli_fetch_object( $res ) ) {
        $out[ 'noises' ][] = [ 'noiseID' => intval( $o->noiseID ), 'editedDataID' => intval( $o->editedDataID ),
                               'noiseType' => $o->noiseType ];
    }
    return $out;
}

/*
 * One scheduler pass over all ACTIVE/CANCELED pipelines of a LIMS db.
 * $opts:
 *   'spawn'      callable( requestID ): start submitone.php for a READY row (called after commit)
 *   'scancel'    callable( gfacID ): cancel a cluster job
 *   'log'        callable( msg, level )
 *   'max_active' int|null: max rows per pipeline in READY..COMPLETE (null = unlimited)
 * Returns true if anything changed.
 */
function pl_scheduler_pass( $db, $lims_db, $opts ) {
    $L     = pl_ident( $lims_db );
    $log   = $opts[ 'log' ] ?? function( $m, $l = 0 ) {};
    $work  = false;
    $res   = pl_query( $db, "SELECT pipelineID FROM $L.analysisPipeline WHERE status IN ('ACTIVE','CANCELED') ORDER BY pipelineID" );
    $pids  = [];
    while ( $o = mysqli_fetch_object( $res ) ) {
        $pids[] = intval( $o->pipelineID );
    }
    foreach ( $pids as $pid ) {
        try {
            $spawn = [];
            $scancel = [];
            mysqli_begin_transaction( $db );
            $changed = pl_process_pipeline( $db, $lims_db, $pid, $opts, $spawn, $scancel );
            mysqli_commit( $db );
        } catch ( Exception $e ) {
            mysqli_rollback( $db );
            $log( "pipeline $lims_db/$pid: " . $e->getMessage(), 0 );
            continue;
        }
        foreach ( $scancel as $g ) {
            if ( isset( $opts[ 'scancel' ] ) ) {
                ( $opts[ 'scancel' ] )( $g );
            }
        }
        foreach ( $spawn as $id ) {
            if ( isset( $opts[ 'spawn' ] ) ) {
                ( $opts[ 'spawn' ] )( $id );
            }
        }
        $work = $work || $changed;
    }
    return $work;
}

function pl_process_pipeline( $db, $lims_db, $pid, $opts, &$spawn, &$scancel ) {
    $L   = pl_ident( $lims_db );
    $log = $opts[ 'log' ] ?? function( $m, $l = 0 ) {};
    $changed = false;

    $res = pl_query( $db, "SELECT status, specJson FROM $L.analysisPipeline WHERE pipelineID=$pid FOR UPDATE" );
    $p   = mysqli_fetch_object( $res );
    if ( !$p || !in_array( $p->status, [ 'ACTIVE', 'CANCELED' ] ) ) {
        return false;
    }
    $spec = json_decode( $p->specJson, true );

    $res  = pl_query( $db, "SELECT requestID, status, statusMsg, currentGfacID, currentHPCARID, statusJson, " .
                           "updateTime, stageSubmitTime FROM $L.autoflowAnalysis WHERE pipelineID=$pid " .
                           "ORDER BY requestID FOR UPDATE" );
    $rows = [];
    $sjs  = [];
    while ( $o = mysqli_fetch_object( $res ) ) {
        $id = intval( $o->requestID );
        $rows[ $id ] = [ 'status' => $o->status, 'statusMsg' => $o->statusMsg, 'currentGfacID' => $o->currentGfacID,
                         'currentHPCARID' => $o->currentHPCARID, 'updateTime' => $o->updateTime,
                         'stageSubmitTime' => $o->stageSubmitTime ];
        $sjs[ $id ]  = json_decode( $o->statusJson, true );
        $rows[ $id ][ 'after' ] = $sjs[ $id ][ 'after' ] ?? [];
    }

    $save = function( $id, $status, $msg, $extra = '' ) use ( $db, $L, &$sjs, &$rows ) {
        $sjs[ $id ][ 'revision' ] = intval( $sjs[ $id ][ 'revision' ] ?? 0 ) + 1;
        pl_query( $db, "UPDATE $L.autoflowAnalysis SET status='" . pl_esc( $db, $status ) . "', " .
                       "statusMsg='" . pl_esc( $db, $msg ) . "', " .
                       "statusJson='" . pl_esc( $db, pl_json( $sjs[ $id ] ) ) . "'" .
                       ( $extra ? ", $extra" : "" ) . " WHERE requestID=$id" );
        $rows[ $id ][ 'status' ] = $status;
    };
    $processed = function( $id ) use ( &$rows ) {
        return [ 'status' => $rows[ $id ][ 'status' ], 'statusMsg' => $rows[ $id ][ 'statusMsg' ],
                 'gfacID' => $rows[ $id ][ 'currentGfacID' ], 'HPCAnalysisRequestID' => $rows[ $id ][ 'currentHPCARID' ],
                 'updateTime' => $rows[ $id ][ 'updateTime' ], 'createTime' => $rows[ $id ][ 'stageSubmitTime' ] ];
    };

    ## 1. finished jobs: record outputs
    foreach ( $rows as $id => $r ) {
        if ( pl_status_class( $r[ 'status' ] ) != 'complete' ) {
            continue;
        }
        $sjs[ $id ][ 'outputs' ]     = pl_fetch_outputs( $db, $lims_db, $r[ 'currentHPCARID' ] );
        $sjs[ $id ][ 'processed' ][] = $processed( $id );
        $save( $id, 'FINISHED', 'Stage finished', "currentGfacID=NULL" );
        $rows[ $id ][ 'currentGfacID' ] = null;
        $log( "pipeline $lims_db/$pid row $id finished", 1 );
        $changed = true;
    }

    ## 2. failed / canceled jobs: cancel cluster job, normalise status
    foreach ( $rows as $id => $r ) {
        $c = pl_status_class( $r[ 'status' ] );
        if ( ( $c != 'failed' && $c != 'canceled' ) ) {
            continue;
        }
        $norm = $c == 'canceled' ? 'CANCELED' : 'FAILED';
        if ( $r[ 'status' ] === $norm && empty( $r[ 'currentGfacID' ] ) ) {
            continue;   # already handled
        }
        if ( !empty( $r[ 'currentGfacID' ] ) ) {
            $scancel[] = $r[ 'currentGfacID' ];
        }
        $sjs[ $id ][ 'processed' ][] = $processed( $id );
        $msg = $r[ 'status' ] === $norm ? $r[ 'statusMsg' ] : strtoupper( $r[ 'status' ] ) . ": " . $r[ 'statusMsg' ];
        $save( $id, $norm, $msg, "currentGfacID=NULL" );
        $rows[ $id ][ 'currentGfacID' ] = null;
        $log( "pipeline $lims_db/$pid row $id $norm: $msg", 1 );
        $changed = true;
    }

    ## 3. blocking / unblocking / readiness
    $t = pl_compute_transitions( $rows, $p->status == 'ACTIVE' ? ( $opts[ 'max_active' ] ?? null ) : 0 );
    foreach ( $t[ 'blocked' ] as $id ) {
        $save( $id, 'BLOCKED', 'A preceding stage failed or was canceled' );
        $changed = true;
    }
    foreach ( $t[ 'unblocked' ] as $id ) {
        $save( $id, 'PENDING', 'Waiting for preceding stages' );
        $changed = true;
    }
    if ( $p->status == 'ACTIVE' ) {
        $byID = $sjs;
        foreach ( $t[ 'ready' ] as $id ) {
            $sj = $sjs[ $id ];
            try {
                $row = $sj[ 'rowKind' ] == 'group'
                     ? [ 'rowKind' => 'group', 'groupName' => $sj[ 'groupName' ], 'stageKey' => $sj[ 'stageKey' ], 'members' => $sj[ 'members' ] ]
                     : [ 'rowKind' => 'triple', 'datasetKey' => $sj[ 'datasetKey' ], 'stageKey' => $sj[ 'stageKey' ] ];
                $sjs[ $id ][ 'params' ]    = pl_resolve_params( $spec, $row );
                $sjs[ $id ][ 'inputs' ]    = pl_resolve_inputs( $spec, $sj, $byID );
                $sjs[ $id ][ 'submitted' ] = $sj[ 'method' ];
            } catch ( Exception $e ) {
                $save( $id, 'FAILED', 'Error preparing stage: ' . $e->getMessage() );
                $log( "pipeline $lims_db/$pid row $id prepare failed: " . $e->getMessage(), 0 );
                $changed = true;
                continue;
            }
            $save( $id, 'READY', 'Job ready to submit',
                   "currentGfacID=NULL, currentHPCARID=NULL, stageSubmitTime=CURRENT_TIMESTAMP()" );
            $spawn[] = $id;
            $log( "pipeline $lims_db/$pid row $id ready ({$sj['method']})", 1 );
            $changed = true;
        }
    }

    ## 4. finish / archive
    $act = pl_pipeline_action( $p->status, $rows );
    if ( $act ) {
        if ( $act == 'finish' ) {
            pl_query( $db, "UPDATE $L.analysisPipeline SET status='FINISHED' WHERE pipelineID=$pid" );
        }
        pl_query( $db, "DELETE FROM $L.autoflowAnalysisHistory WHERE pipelineID=$pid" );
        pl_query( $db, "INSERT INTO $L.autoflowAnalysisHistory SELECT * FROM $L.autoflowAnalysis WHERE pipelineID=$pid" );
        pl_query( $db, "DELETE FROM $L.autoflowAnalysis WHERE pipelineID=$pid" );
        $log( "pipeline $lims_db/$pid " . ( $act == 'finish' ? 'finished' : 'canceled' ) . ", rows archived", 1 );
        $changed = true;
    }
    return $changed;
}
