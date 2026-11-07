<?php
## minimal assertion helpers for the pipeline tests

$T_PASS = 0;
$T_FAIL = 0;

function t_section( $name ) {
    echo "\n== $name\n";
}

function t_ok( $cond, $name ) {
    global $T_PASS, $T_FAIL;
    if ( $cond ) {
        $T_PASS++;
        echo "  PASS $name\n";
    } else {
        $T_FAIL++;
        echo "  FAIL $name\n";
    }
}

function t_eq( $got, $exp, $name ) {
    $ok = $got === $exp;
    t_ok( $ok, $name );
    if ( !$ok ) {
        echo "       got:      " . json_encode( $got ) . "\n";
        echo "       expected: " . json_encode( $exp ) . "\n";
    }
}

function t_throws( $fn, $name ) {
    try {
        $fn();
        t_ok( false, $name );
    } catch ( Exception $e ) {
        t_ok( true, $name );
    }
}

function t_done() {
    global $T_PASS, $T_FAIL;
    echo "\n$T_PASS passed, $T_FAIL failed\n";
    exit( $T_FAIL ? 1 : 0 );
}
