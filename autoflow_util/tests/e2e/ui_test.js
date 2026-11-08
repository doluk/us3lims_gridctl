// Browser test of pipeline_setup.php / pipeline_viewer.php against the e2e test instance
const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );
const BASE = 'http://127.0.0.1:8099/uslims3/uslims3_e2e/';
const OUT  = process.argv[ 2 ];
let pass = 0, fail = 0;
function ok( c, name ) { if ( c ) { pass++; console.log( '  PASS ' + name ); } else { fail++; console.log( '  FAIL ' + name ); } }
function sql( q ) { return execSync( `mariadb -N uslims3_e2e -e "${ q.replace( /"/g, '\\"' ) }"` ).toString().trim(); }
function schedule() {   // one scheduler pass (what submitctl.php does every poll)
  execSync( `php -r 'require "${ process.env.GRIDCTL }/autoflow_util/pipeline_lib.php"; mysqli_report(MYSQLI_REPORT_OFF);
     pl_scheduler_pass( mysqli_connect("localhost","root",""), "uslims3_e2e", [] );'` );
}

( async () => {
  const browser = await chromium.launch();
  const page    = await browser.newPage( { viewport: { width: 1400, height: 1000 } } );
  const errors  = [];
  page.on( 'pageerror', e => errors.push( e.message ) );
  // shared LIMS assets (jquery, common.css, banner) are symlinks outside the test instance: ignore their 404s
  page.on( 'console', m => { if ( m.type() === 'error' && !/Failed to load resource/.test( m.text() ) ) errors.push( m.text() ); } );
  page.on( 'dialog', d => d.accept() );

  console.log( '== queue setup' );
  await page.goto( BASE + 'pltest_login.php?to=queue_setup_3.php' );
  ok( await page.locator( "input[value='Setup Analysis Pipeline']" ).count() === 1, 'queue_setup_3 offers "Setup Analysis Pipeline"' );
  await page.click( "input[value='Setup Analysis Pipeline']" );
  await page.waitForSelector( '#pipeline_editor table' );
  ok( page.url().endsWith( 'pipeline_setup.php' ), 'button opens pipeline_setup.php' );

  console.log( '== editor' );
  const stageRows = await page.locator( '#pipeline_editor fieldset:nth-of-type(2) table tr' ).count();
  ok( stageRows === 4, 'default stages it, mc, pc shown (' + ( stageRows - 1 ) + ')' );
  // enable PCSA stage
  const pcRow = page.locator( '#pipeline_editor fieldset:nth-of-type(2) tr', { hasText: 'pc' } ).last();
  await pcRow.locator( "input[type=checkbox]" ).first().check();
  // dataset override: band volume 0.010 for 2.A.280
  const dsRow = page.locator( '#pipeline_editor fieldset:nth-of-type(3) tr', { hasText: '2.A.280' } );
  await dsRow.locator( "select[data-act='addparam']" ).first().selectOption( 'band_volume-value' );
  await page.locator( '#pipeline_editor fieldset:nth-of-type(3) tr', { hasText: '2.A.280' } ).locator( "input[type=text]" ).first().fill( '0.010' );
  await page.locator( '#pipeline_editor fieldset:nth-of-type(3) tr', { hasText: '2.A.280' } ).locator( "input[type=text]" ).first().press( 'Tab' );
  // leave out MC for 1.B.280
  await page.locator( "input[data-act='dsenable'][data-key='1.B.280'][data-stage='mc']" ).uncheck();
  // global group G1 over all datasets with 2DSA_GL_IT, plus a PCSA_GL stage
  await page.click( "input[data-act='addgroup']" );
  await page.locator( "select[data-act='addgstage']" ).selectOption( 'PCSA_GL' );
  ok( await page.locator( '.group table tr' ).count() === 3, 'group with two global stages' );
  await page.screenshot( { path: OUT + '/setup.png', fullPage: true } );

  console.log( '== validate' );
  await page.click( '#pipeline_validate' );
  await page.waitForLoadState();
  ok( await page.locator( '#pipeline_errors' ).count() === 0, 'no validation errors' );
  const summary = await page.locator( '#pipeline_summary tr' ).count() - 1;
  // it x3, mc x2 (not 1.B.280), pc x3, 2 group stages = 10
  ok( summary === 10, 'summary lists 10 stages (' + summary + ')' );
  ok( await page.locator( "input[data-act='dsenable'][data-key='1.B.280'][data-stage='mc']" ).isChecked() === false, 'editor state kept after validation' );
  await page.screenshot( { path: OUT + '/validated.png', fullPage: true } );

  console.log( '== submit' );
  await page.fill( '#pipeline_name', 'UI test pipeline' );
  await page.click( '#pipeline_submit' );
  await page.waitForSelector( '#pipeline_stages' );
  ok( page.url().includes( 'pipeline_viewer.php?pipelineID=' ), 'redirected to the viewer' );
  ok( ( await page.textContent( '#pipeline_message' ) ).includes( 'was submitted' ), 'confirmation shown' );
  const pid = page.url().split( '=' )[ 1 ];
  ok( sql( `SELECT COUNT(*) FROM autoflowAnalysis WHERE pipelineID=${ pid }` ) === '10', '10 rows created' );
  ok( sql( `SELECT JSON_VALUE(specJson,'$.datasets."2.A.280".params."band_volume-value"') FROM analysisPipeline WHERE pipelineID=${ pid }` ) === '0.01',
      'band volume override stored for 2.A.280' );
  ok( sql( `SELECT CONCAT(name,'|',status,'|',invID,'|',clusterDefault) FROM analysisPipeline WHERE pipelineID=${ pid }` ) === 'UI test pipeline|ACTIVE|12|us3iab-node0',
      'pipeline name, status, owner, cluster' );

  console.log( '== viewer and actions' );
  schedule();
  await page.goto( BASE + 'pipeline_viewer.php?pipelineID=' + pid );
  const ready = await page.locator( '#pipeline_stages tr', { hasText: 'READY' } ).count();
  ok( ready === 3, 'three IT stages READY after a scheduler pass (' + ready + ')' );
  ok( await page.locator( '#pipeline_stages tr', { hasText: 'READY' } ).locator( "input[data-act='cancel']" ).count() === 0, 'no cancel while READY' );
  const itA = sql( `SELECT requestID FROM autoflowAnalysis WHERE pipelineID=${ pid } AND stageKey='it' AND tripleName='1.A.280'` );
  sql( `UPDATE autoflowAnalysis SET status='FAILED', statusMsg='node crashed' WHERE requestID=${ itA }` );
  schedule();
  await page.goto( BASE + 'pipeline_viewer.php?pipelineID=' + pid );
  const blockedAll = sql( `SELECT COUNT(*) FROM autoflowAnalysis WHERE pipelineID=${ pid } AND status='BLOCKED'` );
  ok( blockedAll === '4', 'blocked: mc+pc of 1.A.280 and both global stages (' + blockedAll + ')' );
  await page.click( `input[data-row='${ itA }'][data-act='retry']` );
  await page.waitForSelector( '#pipeline_message' );
  ok( ( await page.textContent( '#pipeline_message' ) ).includes( 'Retry requested' ), 'retry confirmed' );
  ok( sql( `SELECT status FROM autoflowAnalysis WHERE requestID=${ itA }` ) === 'PENDING', 'retried stage PENDING' );
  const pcB = sql( `SELECT requestID FROM autoflowAnalysis WHERE pipelineID=${ pid } AND stageKey='pc' AND tripleName='1.B.280'` );
  await page.click( `input[data-row='${ pcB }'][data-act='skip']` );
  await page.waitForSelector( '#pipeline_message' );
  ok( sql( `SELECT status FROM autoflowAnalysis WHERE requestID=${ pcB }` ) === 'SKIPPED', 'leaf stage skipped' );
  await page.click( `input[data-row='${ pcB }'][data-act='unskip']` );
  await page.waitForSelector( '#pipeline_message' );
  ok( sql( `SELECT status FROM autoflowAnalysis WHERE requestID=${ pcB }` ) === 'PENDING', 'skipped stage re-enabled' );
  await page.screenshot( { path: OUT + '/viewer.png', fullPage: true } );

  await page.goto( BASE + 'pipeline_viewer.php' );
  ok( await page.locator( '#pipeline_list tr', { hasText: 'UI test pipeline' } ).count() === 1, 'pipeline listed' );

  await page.goto( BASE + 'pipeline_viewer.php?pipelineID=' + pid );
  await page.click( '#cancel_pipeline' );
  await page.waitForSelector( '#pipeline_message' );
  ok( ( await page.textContent( '#pipeline_status' ) ).includes( 'CANCELED' ), 'pipeline canceled' );

  console.log( '== validation errors' );
  await page.goto( BASE + 'pltest_login.php' );
  await page.waitForSelector( '#pipeline_editor table' );
  await page.click( "input[data-act='addgroup']" );
  for ( const k of [ '1.B.280', '2.A.280' ] )
    await page.locator( `.group input[type=checkbox][value='${ k }']` ).uncheck();
  await page.click( '#pipeline_validate' );
  await page.waitForLoadState();
  ok( ( await page.locator( '#pipeline_errors' ).textContent() || '' ).includes( 'at least 2 members' ), 'one-member group rejected with a message' );
  await page.screenshot( { path: OUT + '/error.png', fullPage: true } );

  console.log( '== other users' );
  sql( `UPDATE analysisPipeline SET invID=99 WHERE pipelineID=${ pid }` );
  await page.goto( BASE + 'pipeline_viewer.php?pipelineID=' + pid );
  ok( ( await page.content() ).includes( 'not found' ), 'pipeline of another user not shown' );

  ok( errors.length === 0, 'no JavaScript errors' + ( errors.length ? ': ' + errors.join( ' | ' ) : '' ) );
  await browser.close();
  console.log( `\n${ pass } passed, ${ fail } failed` );
  process.exit( fail ? 1 : 0 );
} )();
