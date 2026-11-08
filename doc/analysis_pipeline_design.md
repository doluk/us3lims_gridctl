# Flexible Analysis Pipeline (non-GMP) — Design

Status: **draft for review** — no code changes yet.
Repos affected: `us3lims_gridctl` (scheduler/submit), `us3lims_dbinst` (user interface: pipeline setup in Queue Setup, pipeline viewer),
`us3_sql` (tables / stored procedures). `ultrascan3` is not affected.

---

## 1. Requirements

| # | Requirement |
|---|-------------|
| R1 | Queue a chain of analysis stages per triple, e.g. `2DSA_IT → 2DSA_MC`; next stage starts automatically when its prerequisites finish. |
| R2 | Queue global stages (`2DSA_GL_IT`, `2DSA_GL_MC`, `PCSA_GL`, …) over **user-defined groups** of triples; a global stage waits until all member triples have completed the stage it depends on. |
| R3 | Each stage can be enabled/disabled and configured individually; order is user-defined within logical constraints (conditioning stages first; consumer stages in any order). |
| R4 | Shared parameter template (s/ff0 limits, grid points, grid type, noise, iterations, …); **any** parameter can be overridden per triple (e.g. band volume). |
| R5 | Support PCSA and custom grids (2DSA-CG). |
| R6 | Entry point is the **LIMS job submission** (`us3lims_dbinst`: Queue Setup → method page), for already-edited data, independent of the GMP autoflow chain (no protocol, no report). Reuse the existing engine (`submitctl.php` / `submitone.php` / `gridctl` / `cleanup_gfac`). GMP behaviour must not change. |

---

## 2. Current architecture (facts)

* **Row per triple.** `US_Edit::compose_json()` (`ultrascan3/programs/us_edit/us_edit.cpp:11678`) creates one
  `autoflowAnalysis` row per triple with
  `statusJson = {"to_process":["2DSA","2DSA_FM","FITMEN","2DSA_IT","2DSA_MC","PCSA"]}`.
* **Linear queue.** `submitctl.php:234-417` polls all LIMS DBs; on `COMPLETE`/`FAILED` it moves `submitted` to
  `processed[]`, `array_shift`s the next stage into `submitted`, sets `status=READY` and spawns `submitone.php`.
  After the last stage the row is copied to `autoflowAnalysisHistory` and deleted.
* **Submission by form emulation.** `submitone.php` fills `$_SESSION/$_POST` and `include`s
  `queue_setup_1/2.php` and `2DSA_1.php` / `2DSA-CG_1.php` / `PCSA_1.php` (CLI mode).
  Parameters come **only** from the analysis-profile XML (`p_2dsa/job_<stage>`, `p_pcsa`).
* **Interactive stage.** `job_*` with `interactive` attribute → row set to `WAIT` (FITMEN);
  the GMP GUI later sets `nextWaitStatus`.
* **Status feedback.** `gridctl.php:update_autoflow_status()` updates `autoflowAnalysis` by
  `requestID = gfac.analysis.autoflowAnalysisID AND currentGfacID`. `cleanup_gfac.php:update_autoflow_models()`
  writes `autoflowModelsLink` keyed by `statusJson.submitted` (stage tag) and `autoflowID`.
* **Schema** (`us3_sql/us3.sql:319-370`): `autoflowAnalysis` and `autoflowAnalysisHistory` have identical
  columns; `tripleName`, `filename`, `aprofileGUID CHAR(36)`, `invID`, `autoflowID` are `NOT NULL`;
  `statusJson` is `json`. `autoflowModelsLink.autoflowID` is `NOT NULL`. Rows are created by
  `new_autoflow_analysis_record()` (`us3_autoflow_procs.sql:1283`, `verify_user` checked);
  `set_autoflowAnalysis_auto_increment()` keeps `requestID` unique across live + history tables.
  `us3_sql` has no migration scripts: `us3.sql` is a DROP/CREATE for new instances, procedures are reloaded
  via `us3_procedures.sql`.
* **Global fits already exist in the LIMS**: `queue_setup_3.php` `separate_datasets` = 0 (global) / 1 (separate) /
  2 (composite); `payload_manager.php` appends `-GL` to the method.
  Job-level parameters (s/ff0, grid, iterations, MC, noise fitting) are read **only for dataset 0**;
  dataset-level parameters (`simpoints`, `band_volume`, `radial_grid`, `time_grid`, `editedDataID`, `noiseIDs`)
  are read per dataset (`acquirePostedData`). Without `advanced_review` all datasets receive the same `$_POST`
  (`2DSA_1.php:114-122`).

### Limitations / defects found

| ID | Finding |
|----|---------|
| L1 | Strictly linear `to_process`; no dependencies across triples; no global stages. |
| L2 | No per-triple parameters; `band_volume` always the default `0.015` (`submitone.php` `$defaults_2dsa`). |
| L3 | **Probable bug:** `submitone.php` always uses `channel_parms[0]`; per-channel s/ff0 settings of other channels are ignored. |
| L4 | Inputs are implicit ("latest edit + latest noise", `queue_setup_2.php`). Safe for a linear chain, **racy** once stages run in parallel (e.g. a global IT writes new noise while a per-triple MC is being submitted). |
| L5 | `update_autoflow_models()` inserts `autoflowID=$autoflowID` unquoted → SQL error if the value is NULL/empty. |
| L6 | Analysis profile (`aprofileGUID`) is mandatory in `submitone.php`. |

---

## 3. Design overview

```
analysisPipeline (1 row per pipeline: spec, owner, cluster, status)
   │ expands at submit time into execution rows = ONE ROW PER STAGE (autoflowAnalysis, extended)
   │
   │  dataset 1A          dataset 1B                     group G1 = {1A, 1B}
   │  ┌─────────┐         ┌─────────┐
   │  │ 2DSA_IT │         │ 2DSA_IT │
   │  └──┬───┬──┘         └──┬───┬──┘
   │     │   └──────────┐    │   └───────────────┐
   │  ┌──▼──────┐ ┌─────▼──┐ ┌▼────────┐ ┌───────▼┐      ┌────────────┐  ┌────────────┐
   │  │ 2DSA_MC │ │ PCSA   │ │ 2DSA_MC │ │ PCSA   │      │ 2DSA_GL_IT │  │ 2DSA_GL_MC │
   │  └─────────┘ └────────┘ └─────────┘ └────────┘      └─────▲──────┘  └─────▲──────┘
   │                                                            │ after: 1A.it, 1B.it
   └────────────────────────────────────────────────────────────┴───────────────┘
```

Principles:

1. **Execution unit = one stage = one `autoflowAnalysis` row.** Every row runs at most one cluster job
   during its lifetime, so `currentGfacID`/`currentHPCARID` and the `gridctl` status updates work unchanged.
   Independent stages (e.g. `2DSA_MC` and `PCSA` of the same dataset, or group stages) run **in parallel**.
2. **Dependencies are explicit edges between rows** (`after: [requestID, …]`). A stage starts when all its
   predecessors are finished; there is no queue order.
3. **Explicit inputs**: the edit is the Queue Setup choice; the noise used by each stage is chosen by the
   user (§8) and pinned by ID. Parallel stages therefore cannot pick up each other's noise (fixes L4).
4. **Parameters are layered** (template → stage → dataset/group → dataset/group-stage); the resolved set is
   stored in the row (provenance).
5. Legacy GMP rows (`pipelineID IS NULL`) keep the existing linear `to_process` logic.

---

## 4. Data model

### 4.1 New table `analysisPipeline` (per LIMS DB)

| column | type | note |
|--------|------|------|
| `pipelineID` | INT PK AI | |
| `name` | VARCHAR(255) | user label |
| `invID` | INT | owner (people.personID) |
| `clusterDefault` | TEXT | the one cluster used by all stages |
| `specJson` | JSON | full user spec (§4.3), immutable copy of what was submitted |
| `status` | ENUM(`SETUP`,`ACTIVE`,`FINISHED`,`CANCELED`) DEFAULT `SETUP` | `SETUP`: rows being created, ignored by the scheduler; `ACTIVE`: scheduled; `FINISHED`/`CANCELED`: rows archived (§6.4) |
| `createTime`, `updateTime`, `createUser`, `updateUser` | | |

### 4.2 Extensions to `autoflowAnalysis` / `autoflowAnalysisHistory`

| column | type | note |
|--------|------|------|
| `pipelineID` | INT NULL | NULL ⇒ legacy GMP row |
| `rowKind` | ENUM(`triple`,`group`) DEFAULT `triple` | |
| `groupName` | VARCHAR(80) NULL | for `rowKind=group` |
| `stageKey` | VARCHAR(32) NULL | stage id from the spec (`it`, `mc`, `glit`, …) |

`autoflowID`, `aprofileGUID`, `tripleName`, `filename` stay `NOT NULL` (no change to GMP semantics).
Pipeline rows use sentinels: `autoflowID = 0` (autoflow IDs start at 1, so GMP queries never match),
`aprofileGUID = '00000000-0000-0000-0000-000000000000'`. The discriminator is `pipelineID IS NOT NULL`.
The same sentinel is used for `autoflowModelsLink.autoflowID`.

For `rowKind=group`, `tripleName` holds the group name and `filename` the run filename of the first member
(informational only); members are listed in `statusJson.members`.

The row `status` column carries the stage state directly:
`PENDING` (waiting for predecessors) → `READY` → `SUBMITTED`/`RUNNING`/… (set by `gridctl` as today) →
`COMPLETE` → `FINISHED` (outputs recorded) | `FAILED` | `CANCELED` | `BLOCKED` (a predecessor failed or was
canceled) | `SKIPPED` (disabled before start).

Rows stay in `autoflowAnalysis` until the pipeline is finished or canceled; only then are they moved to history
(§6.4).

### 4.3 Pipeline spec (`analysisPipeline.specJson`)

Parameter keys are the **LIMS form keys** already used in `submitone.php` (`s_value_min`, `ff0_max`,
`s_grid_points`, `band_volume-value`, `mc_iterations`, `max_iterations`, …) plus a few structured keys
(`noise_fit`, `grid`, `pcsa_solute_type`). This removes the XML→form conversion tables for pipeline rows.

```json
{
  "version": 2,
  "cluster": "us3iab-node0",
  "submitter": {"personID": 12, "email": "…", "clusterAuth": ["…"], "userlevel": 1, "advancelevel": 0},
  "requests": [                                 // $_SESSION['request'] from Queue Setup
    {"key": "1.A.280", "rawDataID": 101, "editedDataID": 555, "noiseIDs": [77, 78], "path": "…", "…": "…"},
    {"key": "1.B.280", "rawDataID": 101, "editedDataID": 556, "noiseIDs": [], "…": "…"}
  ],
  "params": {                                   // L0: template (all stages)
    "s_value_min": 1, "s_value_max": 10, "s_grid_points": 64,
    "ff0_min": 1, "ff0_max": 4, "ff0_grid_points": 64,
    "grid": {"type": "uniform"},                // or {"type":"custom","guid":"…","modelID":123}
    "simpoints-value": 200, "band_volume-value": 0.015
  },
  "stages": [                                   // L1: stage template, instantiated for every dataset
    {"id": "it", "method": "2DSA_IT", "enabled": true,
     "params": {"noise_fit": "ti+ri", "max_iterations": 10}, "noise_from": "queue_setup"},
    {"id": "mc", "method": "2DSA_MC", "enabled": true, "after": ["it"],
     "params": {"mc_iterations": 100, "noise_fit": "none"}, "noise_from": "it"},
    {"id": "pc", "method": "PCSA", "enabled": false, "after": ["it"],
     "params": {"curve_type": "IS", "pcsa_solute_type": "013.skv", "vars_count": 10, "noise_fit": "none"},
     "noise_from": "it"}
  ],
  "datasets": {                                 // L2/L3 overrides, keyed by requests[].key
    "1.B.280": {"params": {"band_volume-value": 0.010},
                "stages": {"mc": {"params": {"mc_iterations": 50}}, "pc": {"enabled": true}}}
  },
  "groups": [
    {"name": "G1", "members": ["1.A.280", "1.B.280"],
     "params": {"s_value_max": 20},                               // L2 for the group (job-level)
     "stages": [
       {"id": "glit", "method": "2DSA_GL_IT", "enabled": true, "after_member": ["it"],
        "params": {"max_iterations": 5}, "noise_from": "member:it"},
       {"id": "glmc", "method": "2DSA_GL_MC", "enabled": true, "after_member": ["it"],
        "params": {"mc_iterations": 50}, "noise_from": "member:it"}
     ]}
  ]
}
```

### 4.4 Row `statusJson` v2

Generated from the spec at submit time (one per stage instance); mutable by the scheduler and, while the row
is `PENDING`, by the pipeline viewer.

```json
{
  "version": 2,
  "revision": 3,                                  // optimistic locking (§6.5)
  "method": "2DSA_MC",
  "after": [4711],                                // requestIDs of predecessor rows
  "noise_from": {"requestID": 4711},              // or "queue_setup" | "none"; group: {"member_stage": "it"}
  "members": null,                                // group rows: [{"key":"1.A.280","after":4711,…}, …]
  "params": {…resolved at READY…},
  "inputs":  {"editedDataID": 555, "noiseIDs": [91, 92]},       // pinned at READY
  "outputs": {"modelIDs": [..], "noiseIDs": [..]},              // recorded at FINISHED
  "submitted": "2DSA_MC",                         // kept for cleanup_gfac/get_autoflow_type_id() compatibility
  "processed": [{"status": "COMPLETE", "gfacID": "…", "HPCAnalysisRequestID": 9001, "…": "…"}]
}
```

---

## 5. Stage catalogue

| method | scope | LIMS form | separate_datasets | noise fitting | typical predecessor |
|--------|-------|-----------|-------------------|---------------|---------------------|
| `2DSA` | dataset | 2DSA_1 / 2DSA-CG_1 | 1 | yes | – |
| `2DSA_IT` | dataset | 2DSA_1 / 2DSA-CG_1 (iterations) | 1 | yes | `2DSA` or – |
| `2DSA_MC` | dataset | 2DSA_1 / 2DSA-CG_1 (mc_iterations) | 1 | yes | `2DSA_IT` |
| `PCSA` | dataset | PCSA_1 | 1 | yes | `2DSA_IT` |
| `PCSA_MC` | dataset | PCSA_1 (mc_iterations > 1) | 1 | yes | `2DSA_IT` |
| `2DSA_GL`, `2DSA_GL_IT` | group | 2DSA_1 / 2DSA-CG_1 | 0 | **no** (LIMS limitation) | members' `2DSA_IT` |
| `2DSA_GL_MC` | group | 2DSA_1 / 2DSA-CG_1 | 0 | **no** | members' `2DSA_IT` |
| `PCSA_GL`, `PCSA_GL_MC` | group | PCSA_1 | 0 | **no** | members' `2DSA_IT` |

**Meniscus/bottom fitting is not part of the pipeline.** It is done manually beforehand (2DSA-FM job +
`us_fit_meniscus`); the pipeline starts from the edit selected in Queue Setup, which carries the fitted
meniscus. No pipeline stage changes the edit, and no interactive (`WAIT`) stage exists.
`fit_mb_select` is forced to 0 for all pipeline stages.

Custom grid is **not** a separate method: `params.grid.type = "custom"` selects `2DSA-CG_1/2.php` and
sets `CG_modelID` (R5). It can be set in the template, per stage, per dataset or per group.

`2DSA_GL_*` is the same LIMS method as `2DSA_*` with `separate_datasets = 0`; the method name only determines
scope and default iteration/MC settings.

---

## 6. Dependency model and scheduler

### 6.1 Validation rules (LIMS page on submit + `submitctl` on load)

| V | rule |
|---|------|
| V1 | The expanded graph (dataset `after`, group `after_member`, edges implied by `noise_from`) is acyclic. |
| V2 | At most one `2DSA_IT` per dataset. Every other stage of the dataset must follow it or precede it (directly or transitively). A plain `2DSA` can therefore be a pre-fit before IT or a later consumer (e.g. with a custom grid). |
| V3 | Stages without a path between them are independent and run in parallel. The user may add extra `after` edges to force an order (e.g. to limit cluster load). |
| V4 | A group stage's `after_member` stages must be enabled in **every** member (after dataset overrides); otherwise submission is rejected. |
| V5 | `noise_from` is `queue_setup`, `none`, or a predecessor stage (direct or transitive) **that fits noise**. Group stages: `member:<stage>` with `<stage>` in `after_member` and fitting noise, or `queue_setup`/`none`. Group stages cannot fit noise (`noise_fit` forced `none`); a successor of a group stage therefore cannot take noise from it. |
| V6 | A dataset may be a member of several groups; a group needs ≥ 2 members. |
| V7 | Group job-level parameters come from the group (§7.2); member job-level overrides are ignored for group stages and the setup page warns about them. |
| V8 | Every group stage must follow the `2DSA_IT` stage of **each** member. The LIMS scales global fits (2DSA and PCSA) by each dataset's total concentration from the latest 2DSA-IT model of its edit (`payload_manager::model_concentration`); without one, `file_writer` aborts with `2DSA-IT-MISSING`. |

The user's example satisfies these rules: `2DSA_IT` precedes everything; `2DSA_MC`, `PCSA`, `2DSA_GL_IT`,
`2DSA_GL_MC` only depend on (member) `2DSA_IT` and run in parallel.

**Noise choice is the user's decision on every successor** (setup page: dropdown per stage listing
`queue_setup`, `none`, and the eligible predecessors; default = nearest predecessor that fits noise).
Should the LIMS later allow noise fitting in global fits, group stages become eligible noise sources
(`noise_from: "group:G1/glit"`) under the same rule V5, without schema change.

### 6.2 Readiness

```
row r (status PENDING) becomes READY iff  ∀ p ∈ r.after : p.status == FINISHED
row r becomes BLOCKED                iff  ∃ p ∈ r.after : p.status ∈ {FAILED, CANCELED, BLOCKED}
```

Group rows list the member rows of `after_member` in `after`, so a failing member stage **blocks every stage
of the group** (no stage of the group runs with a reduced member set). Other stages of the members that do not
depend on the failed row continue.

### 6.3 `submitctl.php`

Legacy loop: add `WHERE pipelineID IS NULL` to its SELECT. Without it, a `PENDING` pipeline row (no
`to_process`, no `submitted`) would fall through to the "all processing complete → move to history" branch.

New pipeline loop (same process, after the legacy loop, per LIMS DB):

```
SELECT … FROM autoflowAnalysis JOIN analysisPipeline USING (pipelineID)
       WHERE analysisPipeline.status IN ('ACTIVE','CANCELED') ORDER BY pipelineID       # one query per DB
for each pipeline P (in a transaction, rows locked FOR UPDATE):
    rows with status CANCELED and currentGfacID set: scancel, clear currentGfacID
    for r in P with status in completed_status:   record outputs (§8), processed[], status = FINISHED
    for r in P with status in failed_status:      processed[], status = FAILED (scancel as today)
    repeat until stable: PENDING rows with a failed/canceled/blocked predecessor → BLOCKED
    for r in P with status PENDING and all predecessors FINISHED:
        resolve params (§7), pin inputs (§8), status = READY, spawn submitone.php
    if P.status == ACTIVE and all rows FINISHED/SKIPPED: P.status = FINISHED, archive (§6.4)
    if P.status == CANCELED and no row has a running job:   archive (§6.4)
```

Optional throttle: `max_running_per_pipeline` in `listen-config.php` (READY rows beyond the limit stay
`PENDING`); default unlimited.

### 6.4 History

Rows move to `autoflowAnalysisHistory` **per pipeline** (`DELETE`+`INSERT … SELECT *`, as for GMP rows), so that
group rows can still read member rows' outputs and the viewer shows the complete graph. Archiving happens when
the pipeline is `FINISHED` (all rows `FINISHED`/`SKIPPED`) or `CANCELED`. A pipeline with `FAILED`, `BLOCKED` or
`CANCELED` rows stays `ACTIVE` (shown as "needs attention" in the viewer) until the user retries those rows or
cancels the pipeline; otherwise a failure would be archived before it could be retried.

### 6.5 Runtime edits (pipeline viewer)

Implemented actions (`pl_row_action()`, `pl_cancel_pipeline()`):

| action | allowed on | effect |
|--------|-----------|--------|
| retry | `FAILED`, `CANCELED` without attached cluster job | → `PENDING`; its `BLOCKED` successors return to `PENDING` on the next scheduler pass |
| cancel | `PENDING`, `BLOCKED`, submitted/running (not `READY`: `submitone.php` is submitting it) | → `CANCELED`; the scheduler cancels the cluster job; successors → `BLOCKED` |
| skip | `PENDING` with no stage waiting for it | → `SKIPPED` |
| run (unskip) | `SKIPPED` | → `PENDING` |
| cancel pipeline | `ACTIVE` pipeline | waiting/blocked rows → `CANCELED` at once; rows being submitted or running are canceled (and their jobs cancelled) by the next scheduler pass; then archived |

Every row action is a single `UPDATE … WHERE requestID=? AND status=<seen> AND JSON_VALUE(statusJson,'$.revision')=<seen>`
that increments `revision`; a concurrent change by the scheduler or another user is reported ("please reload").
Not implemented: editing parameters, noise source or predecessors of `PENDING` rows (parameters are resolved from
the stored spec when a row becomes `READY`); a new pipeline can be set up instead.

Creation (LIMS page): insert `analysisPipeline` (`SETUP`), synchronise the `autoflowAnalysis` auto-increment with
the history table (same statement as `set_autoflowAnalysis_auto_increment`, so a `requestID` is never reused),
insert the rows in topological order, then set `ACTIVE` — all in one transaction.

---

## 7. Parameter resolution

### 7.1 Precedence (later wins)

```
L0 spec.params
L1 spec.stages[id].params                          (stage template)
L2 spec.datasets[key].params            | group.params
L3 spec.datasets[key].stages[id].params | group.stages[id].params
```

All parameters are overridable (R4). Resolution happens when the row becomes READY (so viewer edits of
`PENDING` rows take effect); the resolved set is stored in `statusJson.params`.

### 7.2 Group stages: job-level vs dataset-level

The LIMS reads job-level parameters only from dataset 0 (`payload_manager.php:acquirePostedData`), therefore:

| class | keys | source for group stage |
|-------|------|------------------------|
| job-level | `s_value_*`, `ff0_*`, grid points / `grid`, `max_iterations`, `mc_iterations`, PCSA curve options | group L0–L1–L2–L3 |
| dataset-level | `band_volume-value`, `simpoints-value`, `radial_grid`, `time_grid`, `editedDataID`, `noiseIDs` | each member dataset's L0 + L2 (`datasets[key].params`); dataset-stage overrides (L3) do not apply to group stages |

Submission of a group stage uses the LIMS **advanced-review path** (`$_SESSION['advanced_review']=1`,
`separate_datasets=0`), because the non-review path applies the same `$_POST` to all datasets. The method page
can be included only once (it declares functions), so `submitone.php` does what the page's "Next Dataset"
button does: it calls the page's payload manager (`Payload_2DSA`, `Payload_2DSA_CG`, `Payload_PCSA`)
`acquirePostedData()` for datasets 0…N-2 with each dataset's `$_POST`, saves the payload in the session, then
includes the page once with `TIGRE` and the last dataset's `$_POST`. The page acquires the last dataset and
writes one global HPC request (`2DSA-GL-*`, `PCSA-GL-*`).

### 7.3 Analysis profile (GMP) rows

Unchanged; v1 rows keep reading the profile XML. Optionally (separate change) fix L3 by selecting the
`channel_parms` entry whose `channel` matches the row's triple.

---

## 8. Input provenance (edit / noise pinning)

At READY time `submitctl` resolves and stores in `statusJson.inputs`:

* `editedDataID`: the Queue Setup choice (`spec.requests[key]`), constant for the whole pipeline.
* `noiseIDs` according to `noise_from`:
  * `queue_setup` → `spec.requests[key].noiseIDs` (the user's Queue Setup selection),
  * `none` → `[]`,
  * predecessor row → that row's `outputs.noiseIDs` for this dataset,
  * group `member:<stage>` → for each member, the noise outputs of its `<stage>` row.

Outputs are recorded when a row reaches `COMPLETE`: models and noises of the row's `HPCAnalysisRequestID`
via `HPCAnalysisResultData (HPCAnalysisResultID → HPCAnalysisResultType, resultID)`, mapped to datasets by
their `editedDataID` (to verify: noise ↔ editedData link in the `noise` table).

`submitone.php` restores `$_SESSION['request']` from `spec.requests` (instead of re-running
`queue_setup_1/2.php`, which would pick the *latest* edit/noise) and sets `noiseIDs` from `inputs`.

---

## 9. Changes per component

### 9.1 `us3lims_gridctl`

| file | change |
|------|--------|
| `submitctl.php` | legacy SELECT restricted to `pipelineID IS NULL`; new pipeline loop (§6.3): per-pipeline transaction, readiness, BLOCKED propagation, input pinning, history per pipeline. |
| `submitone.php` | v2: parameters from `statusJson.params` (no XML); `$_SESSION` from `spec.submitter`/`spec.requests`; `grid.type=custom` → 2DSA-CG; PCSA solute-type mapping reused; multi-dataset + advanced-review submission for groups (§7.2); input pinning (§8); `fit_mb_select = 0`. |
| `jobmonitor/cleanup_gfac.php` | quote `autoflowID` and accept sentinel 0 (L5); for pipeline rows record outputs (model/noise IDs) in `statusJson.outputs`. |
| `jobmonitor/gridctl.php` | none expected (status update by `requestID`+`currentGfacID` is row-level). |
| new `autoflow_util/pipeline_lib.php` | shared: spec validation (V1–V7), expansion spec → rows (one per stage instance), parameter resolution, noise resolution, readiness. Used by submitctl, submitone and the LIMS pages (`us3lims_dbinst/lib/pipeline.php` looks it up as `$pipeline_lib` from `config.php`, `$class_dir/pipeline_lib.php`, or `/home/us3/lims/bin/autoflow_util/pipeline_lib.php`). |

### 9.2 `us3_sql` (done)

* `us3.sql`: table `analysisPipeline` (§4.1); columns `pipelineID`, `rowKind`, `groupName`, `stageKey` and index
  `(pipelineID)` appended to **both** `autoflowAnalysis` and `autoflowAnalysisHistory` (the history move is
  `INSERT … SELECT *`, so column lists must stay identical).
* `upgrades/2026-11-analysis_pipeline.sql`: idempotent upgrade for existing instances (MariaDB
  `ADD COLUMN IF NOT EXISTS`), ends with a check that both tables have identical column lists.
* **No stored procedures.** The LIMS pages and `gridctl` access the instance DBs with direct SQL (no
  `verify_user`-style calls exist in `us3lims_dbinst`); all pipeline reads/writes live in `pipeline_lib.php`.
  Procedures for the desktop client can be added if a desktop view is wanted later.
* Tested on MariaDB 10.11: fresh install, upgrade of the previous schema (run twice), GMP rows and GMP insert
  path unchanged, history copy of pipeline rows.

### 9.3 `us3lims_dbinst` (user interface)

Available to **all users** (no `advancelevel` restriction). The pipeline is a **fourth way to submit from Queue Setup**, next to "separate / global / composite":

```
queue_setup_1.php  select datasets (unchanged)
queue_setup_2.php  edit profile + noise per dataset (unchanged)  → $_SESSION['request'][i]
queue_setup_3.php  + button "Setup Analysis Pipeline"            → pipeline_setup.php
pipeline_setup.php configure, validate, "Submit Pipeline"        → analysisPipeline + autoflowAnalysis rows
queue_viewer.php   + link per pipeline                           → pipeline_viewer.php
```

**`pipeline_setup.php`** (new; reuses the widgets in `lib/controls.php`, e.g. `s_grid_points()`,
`ff0_grid_points()`, `CG_select_setup()`, `uniform_grid_setup()`, `iterations_option()`, `montecarlo()`,
`band_volume_input()`, `simpoints_input()`, `pcsa_pars_setup()`):

| section | content |
|---------|---------|
| Shared parameters | s / ff0 limits and grid points, grid type (uniform or custom grid via `CG_select_setup`), simpoints, band volume, radial/time grid, cluster (from `$_SESSION['clusterAuth']`, same list as `2DSA_1.php`; **one cluster for all stages of the pipeline**, stored in `analysisPipeline.clusterDefault` and every row's `clusterDefault`). |
| Stages | one row per method of §5: enable checkbox, per-stage parameters (noise fitting, iterations, MC iterations, PCSA curve options), "after" (predecessor stages; default as in §5), **noise source** dropdown (`queue_setup`, `none`, eligible predecessors, §6.1 V5). |
| Datasets | one row per `$_SESSION['request'][i]` (edit + noise as chosen in Queue Setup); columns = overridable parameters, empty = inherit (placeholder shows inherited value); per-dataset stage enable checkboxes; "apply to selected rows". |
| Groups | create named groups from a multi-select of datasets; per group: stages (`2DSA_GL_IT`, `2DSA_GL_MC`, `PCSA_GL`, …), job-level parameters, member predecessor stage (`after_member`), member noise source. |
| Summary | expanded stage graph per dataset/group, validation messages (V1–V7), estimated number of cluster jobs. |

Server-side validation uses `pipeline_lib.php` (shared with gridctl, §9.1); a JS copy only gives immediate
feedback. The page writes `analysisPipeline` and the rows (direct SQL with the LIMS `$link`, like the other
dbinst pages; sequence in §6.5) in one transaction and does **not** submit a job itself; `submitctl`
picks up the READY rows on its next poll.

What the LIMS session provides and the spec stores, so that `submitone.php` no longer has to fake it:

* `requests`: the `$_SESSION['request']` entries (`rawDataID`, `editedDataID`, `noiseIDs`, `path`,
  `filename`, `editFilename`, `editMeniscus`, `dataLeft`, `dataRight`). `submitone.php` restores
  `$_SESSION['request']` from this instead of re-running `queue_setup_1/2.php` (which would pick the
  *latest* edit/noise, not the user's choice).
* `submitter`: `personID`, `email`, `submitter_email`, `userlevel`, `advancelevel`, `clusterAuth`, `gwhostid`.
  Currently `submitone.php` overrides `userlevel = 2` and the e-mail with `us3-admin@…`; for LIMS pipelines
  the stored values are used (notification mails reach the user).

**`pipeline_viewer.php`** (new; linked from `queue_viewer.php`, same authorisation as `is_authorized()`):
pipeline → datasets/groups → stage rows with state, predecessors, noise source, HPC request, cluster job,
message, result links (model/noise). Actions per §6.5: edit/disable `PENDING` rows, retry `FAILED`/`BLOCKED`/
`CANCELED` rows, cancel a row or the whole pipeline (existing cancel path for running jobs).

### 9.4 `ultrascan3`

No change required. Results appear as ordinary models/noises of the edits and are loaded with the existing
programs (e.g. `us_fematch`, `us_pseudo3d_combine`).

---

## 10. Backward compatibility

* v1 rows (`statusJson` without `version`) follow the existing code path; GMP GUI and reporter are unaffected.
* `statusJson.submitted` keeps the method string, so `get_autoflow_type_id()` and existing consumers work.
* v2 rows have `pipelineID IS NOT NULL` and `autoflowID = 0`; GMP tools select by `autoflowID` and will not see them.

---

## 11. Implementation phases

| phase | content | repos |
|-------|---------|-------|
| P0 | Schema: `analysisPipeline`, new columns, upgrade script (**done**) | us3_sql |
| P1 | `pipeline_lib.php` (validation, expansion, resolution) + unit tests (CLI, fixtures) (**done**) | gridctl |
| P2 | `submitctl.php` pipeline loop (scheduling incl. group readiness) (**done**) | gridctl |
| P3 | `submitone.php` v2 params, custom grid, PCSA, input pinning — dataset stages (**done**; group rows fail with "can not be submitted yet") | gridctl |
| P4 | Groups: multi-dataset advanced-review submission, member predecessors, blocking (**done**) | gridctl, dbinst (PCSA fix) |
| P5 | `cleanup_gfac.php` sentinel autoflowID, outputs per row (**done**: outputs are read by the scheduler from `HPCAnalysisResultData`; model links work unchanged) | gridctl |
| P6 | `pipeline_setup.php` (from queue_setup_3) + `pipeline_viewer.php` (from queue_viewer) (**done**) | dbinst |
| P7 | Fix L3 for GMP rows | gridctl |

P1–P3 give R1, R3, R4, R5 for single datasets; P4 adds R2.

---

## 12. Decisions

| # | decision |
|---|----------|
| D1 | Entry point: LIMS Queue Setup (`us3lims_dbinst`), no desktop program. |
| D2 | Global groups are user-defined. |
| D3 | Every parameter can be overridden per dataset (and per group for group stages, §7.2). |
| D4 | Meniscus/bottom fit stays manual, outside the pipeline (no `2DSA_FM`, `FITMEN`). |
| D5 | Pipeline setup is available to all users. |
| D6 | One cluster for the whole pipeline. |
| D7 | Independent stages run in parallel (one row per stage; §3, §6). |
| D8 | A failing member stage blocks all stages of the group (§6.2). |
| D9 | Global fits do not fit noise (current LIMS limitation); the noise source of every successor is chosen by the user (§6.1 V5). |
| D10 | Viewer authorisation as in `queue_viewer.php` (`is_authorized()`). |

## 13. Implementation notes (P1–P3)

* **Code**: `autoflow_util/pipeline_lib.php` (pure validation/expansion/resolution/POST builder/transitions +
  DB layer `pl_create_pipeline()`, `pl_scheduler_pass()`); `submitctl.php` calls `pl_scheduler_pass()` per LIMS
  DB before the GMP loop; `submitone.php` branches on `pipelineID` (`submit_pipeline_prepare()`), the LIMS page
  is included at top level as in the GMP path.
* **Tests**: `php autoflow_util/tests/pipeline_lib_test.php` (pure functions);
  `php autoflow_util/tests/pipeline_db_test.php <us3_sql>/us3.sql` (scheduler against a scratch MariaDB database,
  simulating `submit_local`/`gridctl`/`cleanup_gfac`; also passes with mysqli exception mode,
  `PL_TEST_STRICT=1`);
  `autoflow_util/tests/e2e/run.sh` (real `us3lims_dbinst` pages from `submitone.php` to the HPC request and job
  XML, cluster submission stubbed; modifies the machine, disposable containers only).
* **Safety for GMP rows**: the GMP loop filters `pipelineID IS NULL` whenever the column exists, independent of
  whether `pipeline_lib.php` could be loaded. Databases without the upgrade behave exactly as before.
* **Status mapping**: `COMPLETE`/`DONE` → outputs recorded → `FINISHED`; `FAILED`, `ERROR`, `SUBMIT_TIMEOUT`,
  `RUN_TIMEOUT`, `DATA_TIMEOUT`, `FAILED_DATA` → `FAILED` (original status kept as message prefix, cluster job
  cancelled with `scancel` as for GMP rows); `CANCELED` stays `CANCELED`.
* **Method semantics**: `*_IT` sets `iterations_option=1`; `*_MC` requires `mc_iterations > 1`, other methods
  force `mc_iterations=1` (validation error if set > 1); noise fitting via `noise_fit` (`none|ti|ri|ti+ri`).
* **Noise from MC stages**: if a source stage produced several noise records of one type for an edit, the latest
  (highest `noiseID`) is used.
* **Session**: e-mail and user level of the submitting user from the spec (no `us3-admin` override); cluster
  authorisations read from `people` at submit time. The LIMS pages require `userlevel >= 2`.
* **Known gap**: a row stays `READY` if `submitone.php` dies before `submit_local.php` updates it (same as GMP).
* **End-to-end findings (P4)**: global fits require each member's 2DSA-IT model (→ V8); a plain `2DSA` after IT
  is a valid consumer (→ V2 relaxed); `HPC_PCSA::HPCJobParameters()` in `us3lims_dbinst` called `bind_param()`
  on a query without placeholders, which throws on PHP 8 and broke every PCSA submission (fixed in dbinst). The
  CLI path also relies on the instance `config.php` setting `$is_cli` and including `elog.php`, and on the
  LIMS reaching the `gfac` DB (same as the GMP path).

### 13.1 LIMS pages (P6)

* `queue_setup_3.php`: button "Setup Analysis Pipeline" (only if the library and the schema are present).
* `pipeline_setup.php` + `js/pipeline_setup.js`: stage editor (shared parameters, dataset stages with
  predecessors / noise source / settings / any further parameter, per-dataset stage selection and overrides,
  groups with global stages), cluster selection as on the method pages (`tigre()`), *Validate* (server-side
  `pl_expand_spec()`, summary of the stages to be created) and *Submit Pipeline* (`pl_create_pipeline()`).
  Datasets, edits and noises come from the queue in the session, never from the browser.
* `pipeline_viewer.php`: list (own pipelines; all for user level ≥ 4), stage table with state, predecessors,
  noise source, HPC request, results and the actions of §6.5. Linked from `queue_viewer.php`.
* Tests: `autoflow_util/tests/e2e/ui_run.sh` (Playwright: queue setup button → editor → validate → submit →
  viewer actions → validation error → access of another user's pipeline).

## 14. Open questions

None blocking. Noise ↔ edit mapping is resolved (`noise.editedDataID`, `model.editedDataID`); the advanced-review
CLI path for group submissions is verified end to end (§7.2, §13). Not yet tested: a real cluster run and
multi-speed experiments (V8 is stricter than the LIMS there, which needs no 2DSA-IT model for >1 speed step).
