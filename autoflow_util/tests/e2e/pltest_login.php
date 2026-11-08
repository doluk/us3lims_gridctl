<?php
// TEST ONLY: session as after queue_setup_1/2 for 3 datasets of run1
include 'config.php';
session_name( "PHPSESS_" . $dbname );
session_start();
$_SESSION = [
  'id' => 12, 'loginID' => 12, 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'phone' => '1',
  'email' => 'ada@example.org', 'submitter_email' => 'ada@example.org', 'userlevel' => 2, 'advancelevel' => 0,
  'instance' => $dbname, 'user_id' => 'Ada_Lovelace_aaaaaaaa-0000-0000-0000-000000000012',
  'clusterAuth' => [ 'us3iab-node0' ], 'gwhostid' => 'e2ehost', 'request' => [],
];
foreach ( [ [ 101, 555, [ 77 ], '1.A.280' ], [ 102, 556, [], '1.B.280' ], [ 103, 557, [], '2.A.280' ] ] as $r )
  $_SESSION['request'][] = [ 'rawDataID' => $r[0], 'path' => '/data/run1', 'filename' => "run1.RA.{$r[3]}.auc",
    'editedDataID' => $r[1], 'editFilename' => "run1.e1.RA.{$r[3]}.xml", 'noiseIDs' => $r[2],
    'editMeniscus' => 5.85, 'dataLeft' => 5.9, 'dataRight' => 7.1 ];
header( 'Location: ' . ( $_GET['to'] ?? 'pipeline_setup.php' ) );
