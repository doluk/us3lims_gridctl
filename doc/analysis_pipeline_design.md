# Flexible Analysis Pipeline (non-GMP) — Design

Status: **draft for review** — no code changes yet.
Repos affected: `us3lims_gridctl` (scheduler/submit), `us3lims_dbinst` (LIMS forms, minor),
`ultrascan3` (client GUI), `us3_sql` (tables / stored procedures).

---

## 1. Requirements

| # | Requirement |
|---|-------------|
| R1 | Queue a chain of analysis stages per triple, e.g. `2DSA_IT → 2DSA_MC`; next stage starts automatically when its prerequisites finish. |
| R2 | Queue global stages (`2DSA_GL_IT`, `2DSA_GL_MC`, `PCSA_GL`, …) over **user-defined groups** of triples; a global stage waits until all member triples have completed the stage it depends on. |
| R3 | Each stage can be enabled/disabled and configured individually; order is user-defined within logical constraints (conditioning stages first; consumer stages in any order). |
| R4 | Shared parameter template (s/ff0 limits, grid points, grid type, noise, iterations, …); **any** parameter can be overridden per triple (e.g. band volume). |
| R5 | Support PCSA and custom grids (2DSA-CG). |
| R6 | **Standalone** entry point for already-edited data, independent of the GMP autoflow chain (no protocol, no report). Reuse the existing engine (`submitctl.php` / `submitone.php` / `gridctl` / `cleanup_gfac`). GMP behaviour must not change. |

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
            ┌─────────────────────── analysisPipeline (new, 1 row per pipeline) ──────────────────────┐
            │ spec: template params, stage template, triples (+overrides), groups (+overrides)       │
            └────────────────────────────────────────────────────────────────────────────────────────┘
                     │ expands to execution rows (existing table, extended)
      ┌──────────────┼──────────────────────────────┬───────────────────────────────────┐
autoflowAnalysis  autoflowAnalysis              autoflowAnalysis                  autoflowAnalysis
kind=triple 1A    kind=triple 1B                kind=triple 2A                    kind=group "G1" {1A,1B,2A}
2DSA_IT→2DSA_MC   2DSA_IT→2DSA_MC→PCSA          2DSA_IT                           2DSA_GL_IT , 2DSA_GL_MC
      │                │                             │                                  ▲
      └────────────────┴──── 2DSA_IT COMPLETE ───────┴──────── waitFor (all members) ───┘
```

Principles:

1. **Execution unit = row** (triple or group). One active cluster job per row → reuses
   `currentGfacID`/`currentHPCARID`/`gridctl` status logic unchanged. Parallelism comes from many rows.
2. **Stages are a DAG**, not a queue: each stage declares in-row predecessors (`after`) and cross-row
   predecessors (`waitFor`). Linear `to_process` (v1) remains supported.
3. **Explicit inputs**: edit and noise IDs are resolved and *pinned* when a stage becomes ready (fixes L4).
4. **Parameters are layered** (template → stage → triple/group → triple/group-stage); resolution happens in
   `submitone.php` and is stored with the stage for provenance.

---

## 4. Data model

### 4.1 New table `analysisPipeline` (per LIMS DB)

| column | type | note |
|--------|------|------|
| `pipelineID` | INT PK AI | |
| `name` | VARCHAR(255) | user label |
| `invID` | INT | owner (people.personID) |
| `clusterDefault` | VARCHAR(64) | default for rows |
| `specJson` | LONGTEXT | full user spec (§4.3), immutable copy of what was submitted |
| `status` | ENUM(`ACTIVE`,`FINISHED`,`FAILED`,`CANCELED`) | aggregate |
| `createTime`, `updateTime`, `createUser`, `updateUser` | | |

### 4.2 Extensions to `autoflowAnalysis` / `autoflowAnalysisHistory`

| column | type | note |
|--------|------|------|
| `pipelineID` | INT NULL | NULL ⇒ legacy GMP row |
| `rowKind` | ENUM(`triple`,`group`) DEFAULT `triple` | |
| `groupName` | VARCHAR(80) NULL | for `rowKind=group` |

`autoflowID`, `aprofileGUID`, `tripleName`, `filename` stay `NOT NULL` (no change to GMP semantics). Standalone
rows use sentinels: `autoflowID = 0` (autoflow IDs start at 1, so GMP queries never match),
`aprofileGUID = '00000000-0000-0000-0000-000000000000'`. The discriminator is `pipelineID IS NOT NULL`.
The same sentinel is used for `autoflowModelsLink.autoflowID`.

For `rowKind=group`, `tripleName`/`filename` hold the group name / run filename of the first member
(informational only); members are listed in `statusJson.members`.

Rows stay in `autoflowAnalysis` until **all rows of the same pipeline** are terminal (a group may still need
member results); only then are they moved to history (§6.4).

### 4.3 Pipeline spec (`analysisPipeline.specJson`)

Parameter keys are the **LIMS form keys** already used in `submitone.php` (`s_value_min`, `ff0_max`,
`s_grid_points`, `band_volume-value`, `mc_iterations`, `max_iterations`, …) plus a few structured keys
(`noise`, `custom_grid`, `pcsa_solute_type`, `meniscus_*`). This removes the XML→form conversion tables
for standalone rows.

```json
{
  "version": 2,
  "cluster": "us3iab-node0",
  "params": {                                   // L0: template (all stages)
    "s_value_min": 1, "s_value_max": 10, "s_grid_points": 64,
    "ff0_min": 1, "ff0_max": 4, "ff0_grid_points": 64,
    "grid": {"type": "uniform"},                // or {"type":"custom","guid":"…","modelID":123}
    "simpoints-value": 200, "band_volume-value": 0.015
  },
  "stages": [                                   // stage template, applied to every triple
    {"id": "fm",  "method": "2DSA_FM", "enabled": false,
     "params": {"noise": "ti+ri", "meniscus_range": 0.03, "meniscus_points": 11, "fit_mb_select": 1}},
    {"id": "fit", "method": "FITMEN_AUTO", "enabled": false, "after": ["fm"]},
    {"id": "it",  "method": "2DSA_IT", "enabled": true, "after": ["fit"],
     "params": {"noise": "ti+ri", "max_iterations": 10}},
    {"id": "mc",  "method": "2DSA_MC", "enabled": true, "after": ["it"],
     "params": {"mc_iterations": 100}, "inputs": {"noise_from": "it"}},
    {"id": "pc",  "method": "PCSA", "enabled": false, "after": ["it"],
     "params": {"curve_type": "IS", "pcsa_solute_type": "013.skv", "vars_count": 10},
     "inputs": {"noise_from": "it"}}
  ],
  "triples": [
    {"rawDataID": 101, "editedDataID": 555, "triple": "1.A.280"},
    {"rawDataID": 101, "editedDataID": 556, "triple": "1.B.280",
     "params": {"band_volume-value": 0.010},                      // L2: triple override
     "stages": {"mc": {"params": {"mc_iterations": 50}},          // L3: triple-stage override
                "pc": {"enabled": true}}}
  ],
  "groups": [
    {"name": "G1", "members": ["1.A.280", "1.B.280"],
     "params": {"s_value_max": 20},                               // L2 for the group (job-level)
     "stages": [
       {"id": "glit", "method": "2DSA_GL_IT", "enabled": true,
        "waitFor": {"member_stage": "it"}, "params": {"max_iterations": 5},
        "inputs": {"noise_from": "member:it"}},
       {"id": "glmc", "method": "2DSA_GL_MC", "enabled": true,
        "waitFor": {"member_stage": "it"}, "params": {"mc_iterations": 50},
        "inputs": {"noise_from": "member:it"}}
     ],
     "onMemberFailure": "block"}                                 // or "drop_member"
  ]
}
```

### 4.4 Row `statusJson` v2

Generated from the spec at creation time; mutable by the scheduler and (for not-yet-started stages) by the
user.

```json
{
  "version": 2,
  "revision": 7,                                  // optimistic locking (§6.5)
  "members": [4711, 4712],                        // group rows only: requestIDs of member rows
  "stages": {
    "it": {"method": "2DSA_IT", "enabled": true, "after": [], "params": {…resolved…},
           "state": "COMPLETE", "gfacID": "…", "HPCAnalysisRequestID": 9001,
           "inputs": {"editedDataID": 555, "noiseIDs": []},
           "outputs": {"modelIDs": [..], "noiseIDs": [..]},
           "createTime": "…", "updateTime": "…", "statusMsg": "…"},
    "mc": {"method": "2DSA_MC", "enabled": true, "after": ["it"], "inputs_spec": {"noise_from": "it"},
           "state": "PENDING"}
  },
  "order": ["it", "mc"],                          // user-chosen tie-break among ready stages
  "submitted": "2DSA_MC",                         // kept for cleanup_gfac/get_autoflow_type_id() compatibility
  "submitted_id": "mc"
}
```

Stage `state`: `PENDING` → `READY` → `SUBMITTED` → (`RUNNING` …) → `COMPLETE` | `FAILED` | `CANCELED` |
`SKIPPED` (disabled) | `BLOCKED` (predecessor failed / member failed) | `WAIT` (interactive).

---

## 5. Stage catalogue

| method | scope | LIMS form | separate_datasets | role | default noise input |
|--------|-------|-----------|-------------------|------|---------------------|
| `2DSA` | triple | 2DSA_1 / 2DSA-CG_1 | 1 | conditioning | none (fits noise) |
| `2DSA_FM` | triple | 2DSA_1 (fit_mb_select) | 1 | conditioning | none |
| `FITMEN` / `FITMEN_AUTO` | triple | interactive / auto meniscus | – | conditioning (writes edit) | – |
| `2DSA_IT` | triple | 2DSA_1 / 2DSA-CG_1 (iterations) | 1 | conditioning | none (fits noise) |
| `2DSA_MC` | triple | 2DSA_1 / 2DSA-CG_1 (mc_iterations) | 1 | consumer | `it` |
| `PCSA` | triple | PCSA_1 | 1 | consumer | last conditioning |
| `PCSA_MC` | triple | PCSA_1 (mc_iterations > 1) | 1 | consumer | last conditioning |
| `2DSA_GL`, `2DSA_GL_IT` | group | 2DSA_1 / 2DSA-CG_1 | 0 | consumer (group) | member:last conditioning |
| `2DSA_GL_MC` | group | 2DSA_1 / 2DSA-CG_1 | 0 | consumer (group) | member:last conditioning |
| `PCSA_GL`, `PCSA_GL_MC` | group | PCSA_1 | 0 | consumer (group) | member:last conditioning |

Custom grid is **not** a separate method: `params.grid.type = "custom"` selects `2DSA-CG_1/2.php` and
sets `CG_modelID` (R5). It can be set in the template, per stage, per triple or per group.

`2DSA_GL_*` is the same LIMS method as `2DSA_*` with `separate_datasets = 0`; the method name in the spec only
determines scope and default iteration/MC settings.

---

## 6. Dependency model and scheduler

### 6.1 Validation rules (client + `submitctl` on load)

| V | rule |
|---|------|
| V1 | Graph (in-row `after` + cross-row `waitFor` + implicit edges from `inputs`) is acyclic. |
| V2 | **Conditioning stages are totally ordered** in canonical order `2DSA < 2DSA_FM < FITMEN* < 2DSA_IT` (each may change meniscus or noise). Disabled ones are skipped; edges are re-linked to the previous enabled stage. |
| V3 | Every **consumer** stage depends on the last enabled conditioning stage of its triple (implicit edge). Consumers have no mandatory order among each other; the user order (`order`) only serialises them in the row. |
| V4 | A group stage `waitFor.member_stage` must reference a stage enabled in **every** member (after triple overrides); otherwise creation is rejected. |
| V5 | `inputs.noise_from` must reference a predecessor (direct or transitive); it adds an implicit edge. |
| V6 | A triple may be a member of several groups; a group needs ≥ 2 members. |
| V7 | Group job-level parameters come from the group (§7.2); member job-level overrides are ignored for group stages and the client warns about them. |

The user's example satisfies these rules: `2DSA_IT` (conditioning) precedes everything; `2DSA_MC`, `PCSA`,
`2DSA_GL_IT`, `2DSA_GL_MC` are consumers whose relative order is free.

### 6.2 Readiness

```
stage s in row r is READY iff
    s.enabled
and s.state == PENDING
and ∀ p ∈ s.after          : r.stages[p].state ∈ {COMPLETE, SKIPPED}
and (r.kind == group ⇒ ∀ m ∈ r.members : m.stages[s.waitFor.member_stage].state == COMPLETE)
and r has no stage in {SUBMITTED, RUNNING, WAIT}        # one job per row
```

Among several READY stages of a row, the first in `order` is submitted.

### 6.3 `submitctl.php` loop (v2 rows)

```
for each LIMS db, for each row (SELECT … FOR UPDATE inside a transaction):
    if statusJson.version != 2: legacy path (unchanged)
    sync the active stage from row.status (COMPLETE/FAILED/…) → stage.state, record outputs (§8)
    propagate failure: successors → BLOCKED; groups with onMemberFailure=block → BLOCKED,
                       drop_member → remove member from group.members (log + statusMsg)
    pick next READY stage (§6.2); resolve + pin inputs (§8); set submitted/submitted_id;
         status=READY; spawn submitone.php
    if all stages terminal and (rowKind=group or no group waits on this row): mark row FINISHED
    if all rows of pipelineID terminal: move rows to history; analysisPipeline.status := aggregate
```

Polling cost: rows of a pipeline are read once per cycle; group readiness needs the member rows → read rows
grouped by `pipelineID` (one query per DB per cycle).

### 6.4 History

Moving a member row to `autoflowAnalysisHistory` before its group has run would hide its outputs from the
group; therefore v2 rows move to history **per pipeline**, not per row.

### 6.5 Runtime edits

The client may toggle `enabled`, change `params`, `order` and `after` of stages in state `PENDING` only.
Writes use `UPDATE … WHERE requestID=? AND JSON_EXTRACT(statusJson,'$.revision')=?` and increment
`revision`; the scheduler does the same. Conflicts are retried by the client.

Cancel: stage → `CANCELED` (running job: existing `scancel` path); successors → `BLOCKED`.
Retry: `FAILED`/`BLOCKED` → `PENDING` (successors reset to `PENDING`).

---

## 7. Parameter resolution

### 7.1 Precedence (later wins)

```
L0 spec.params
L1 spec.stages[id].params                         (stage template)
L2 triple.params            | group.params
L3 triple.stages[id].params | group.stages[id].params
```

All parameters are overridable (R4). Resolution happens once, when the stage becomes READY, and the resolved
set is stored in `statusJson.stages[id].params` (provenance; the run is reproducible from the row alone).

### 7.2 Group stages: job-level vs dataset-level

The LIMS reads job-level parameters only from dataset 0 (`payload_manager.php:acquirePostedData`), therefore:

| class | keys | source for group stage |
|-------|------|------------------------|
| job-level | `s_value_*`, `ff0_*`, grid points / `grid`, `max_iterations`, `mc_iterations`, noise *fitting* options, PCSA curve options | group L0–L1–L2–L3 |
| dataset-level | `band_volume-value`, `simpoints-value`, `radial_grid`, `time_grid`, `editedDataID`, `noiseIDs` | each member triple's L0 + L2 (`triple.params`); triple-stage overrides (L3) do not apply to group stages |

Submission of a group stage must use the LIMS **advanced-review path** (`$_SESSION['advanced_review']=1`):
include `2DSA_1.php` once to initialise, once per dataset 0…N-2 with `next` + that dataset's `$_POST`, and
once with `TIGRE` + the last dataset's `$_POST`. The current non-review path would apply identical
dataset-level values to all members.

### 7.3 Analysis profile (GMP) rows

Unchanged; v1 rows keep reading the profile XML. Optionally (separate change) fix L3 by selecting the
`channel_parms` entry whose `channel` matches the row's triple.

---

## 8. Input provenance (edit / noise pinning)

At READY time `submitctl` (or `submitone`, before including the LIMS forms) resolves:

* `editedDataID`: for triples, the latest edit of the triple **after** the last conditioning stage
  (FITMEN may write a new meniscus); for groups, each member's pinned `editedDataID` of its
  `waitFor.member_stage`.
* `noiseIDs`: outputs of the stage named in `inputs.noise_from`, looked up via
  `HPCAnalysisResultData (HPCAnalysisResultID → HPCAnalysisResultType='noise', resultID)` for the stage's
  `HPCAnalysisRequestID`, filtered by the dataset's `editedDataID`. `none` ⇒ no noise subtracted.

`submitone.php` then overwrites `$_SESSION['request'][$i]['editedDataID']` and `['noiseIDs']` after
`queue_setup_2.php` (keys set at `queue_setup_2.php:70-72`), instead of relying on "latest". When the edit is
replaced, the dependent keys `editFilename`, `editMeniscus`, `dataLeft`, `dataRight` must be refreshed
from the same `editedData` record.

On COMPLETE, `cleanup_gfac`/`submitctl` record `outputs.modelIDs/noiseIDs` in the stage
(`update_autoflow_models()` already collects model/edit per stage tag; extend with `submitted_id`).

To verify against the schema: noise ↔ editedData linkage for global fits (one noise set per dataset).

---

## 9. Changes per component

### 9.1 `us3lims_gridctl`

| file | change |
|------|--------|
| `submitctl.php` | v2 branch (§6.3); per-pipeline transaction; failure propagation; history per pipeline. v1 path untouched. |
| `submitone.php` | v2: parameters from `statusJson.stages[id].params` (no XML); `grid.type=custom` → 2DSA-CG; PCSA solute-type mapping reused; multi-dataset + advanced-review submission for groups (§7.2); input pinning (§8); interactive stages → `WAIT`. |
| `jobmonitor/cleanup_gfac.php` | quote `autoflowID` and accept sentinel 0 (L5); key model links by `submitted_id`; store outputs into stage. |
| `jobmonitor/gridctl.php` | none expected (status update by `requestID`+`currentGfacID` is row-level). |
| new `autoflow_util/pipeline_lib.php` | shared: spec validation (V1–V7), expansion spec → rows, parameter resolution, readiness. Used by submitctl, submitone, and the LIMS page. |

### 9.2 `us3_sql`

* `us3.sql`: `CREATE TABLE analysisPipeline` (§4.1) and the three columns of §4.2 in **both**
  `autoflowAnalysis` and `autoflowAnalysisHistory` (the history move is `INSERT … SELECT *`, so column lists
  must stay identical), index `(pipelineID)`.
* New upgrade script for existing instances (repo has none yet), e.g. `upgrades/2026-analysis_pipeline.sql`:

  ```sql
  CREATE TABLE IF NOT EXISTS analysisPipeline (
    pipelineID     int(11)      NOT NULL AUTO_INCREMENT,
    name           varchar(255) NOT NULL,
    invID          int(11)      NOT NULL,
    clusterDefault text         DEFAULT "localhost",
    specJson       json,
    status         enum('ACTIVE','FINISHED','FAILED','CANCELED') DEFAULT 'ACTIVE',
    createTime     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updateTime     timestamp    DEFAULT 0 ON UPDATE CURRENT_TIMESTAMP,
    createUser     varchar(128) DEFAULT (current_user()),
    updateUser     varchar(128) DEFAULT "",
    PRIMARY KEY (pipelineID),
    KEY (invID)
  ) ENGINE=InnoDB;

  ALTER TABLE autoflowAnalysis
    ADD COLUMN pipelineID int(11) DEFAULT NULL,
    ADD COLUMN rowKind    enum('triple','group') NOT NULL DEFAULT 'triple',
    ADD COLUMN groupName  varchar(80) DEFAULT NULL,
    ADD KEY (pipelineID);
  -- identical ALTER for autoflowAnalysisHistory
  ```

  Columns are appended at the end so existing positional `SELECT *` copies and readers keep working.
* `us3_autoflow_procs.sql` (or new `us3_pipeline_procs.sql`, sourced from `us3_procedures.sql`):
  `new_analysis_pipeline()` (creates pipeline + all rows in one transaction, returns `pipelineID`),
  `new_analysis_pipeline_row()`, `get_analysis_pipeline()`, `get_analysis_pipeline_rows()`,
  `update_analysis_pipeline_row_json(requestID, revision, json)` (revision-checked, §6.5),
  `cancel_analysis_pipeline()`. All with `verify_user` and owner check (`invID`), like the existing procedures.
  Existing `read_autoflowAnalysis_record` is unchanged.

### 9.3 `ultrascan3` (client)

New program `us_analysis_pipeline` (or a mode of `us_analysis_profile` / `us_autoflow_analysis`):

* **Data**: select run(s) → triples with their latest edits (existing `US_DataLoader`).
* **Template tab**: stage list (checkbox, method, up/down for consumers; conditioning stages fixed order),
  shared parameters, grid type incl. custom-grid model picker, PCSA options.
* **Triples tab**: table triple × parameter; empty cell = inherit, edited cell = override (bold);
  per-triple stage enable matrix.
* **Groups tab**: create groups by multi-select; group parameters; group stages + `waitFor`.
* **Monitor**: tree pipeline → rows → stages with state, cluster job, msg; actions enable/disable/retry/cancel
  for PENDING/FAILED stages (revision-checked writes). Reuse widgets from `us_autoflow_analysis`.
* Validation (V1–V7) mirrored client-side for immediate feedback; server remains authoritative.

### 9.4 `us3lims_dbinst`

Optional web page (`pipeline_setup.php`, `pipeline_viewer.php`) for users without the desktop client, using
`pipeline_lib.php`. No change to existing 2DSA/PCSA forms required.

---

## 10. Backward compatibility

* v1 rows (`statusJson` without `version`) follow the existing code path; GMP GUI and reporter are unaffected.
* `statusJson.submitted` keeps the method string, so `get_autoflow_type_id()` and existing consumers work.
* v2 rows have `pipelineID IS NOT NULL` and `autoflowID = 0`; GMP tools select by `autoflowID` and will not see them.

---

## 11. Implementation phases

| phase | content | repos |
|-------|---------|-------|
| P0 | Schema: `analysisPipeline`, new columns, upgrade script, procedures | us3_sql |
| P1 | `pipeline_lib.php` (validation, expansion, resolution) + unit tests (CLI, fixtures) | gridctl |
| P2 | `submitctl.php` v2 scheduler, per-triple stages only (no groups) | gridctl |
| P3 | `submitone.php` v2 params, custom grid, PCSA, input pinning | gridctl |
| P4 | Groups: multi-dataset advanced-review submission, waitFor, failure policy | gridctl (+ dbinst if form fixes needed) |
| P5 | `cleanup_gfac.php` NULL autoflowID, outputs per stage | gridctl |
| P6 | Client GUI setup + monitor | ultrascan3 |
| P7 | Optional LIMS web pages; fix L3 for GMP | dbinst, gridctl |

P1–P3 give R1, R3, R4, R5 for single triples; P4 adds R2.

---

## 12. Open questions

1. **FITMEN in standalone pipelines**: only `FITMEN_AUTO`, or also interactive (row → `WAIT`, client launches
   `us_fit_meniscus` and clears the wait)?
2. **Consumer parallelism per triple**: proposed one job per row (consumers of a triple run sequentially in
   user order). Need true parallel MC + PCSA on the same triple? (would require one row per stage).
3. **Group failure default**: `block` (proposed) or `drop_member`?
4. **Global noise**: should a later per-triple stage be allowed to use noise from a group stage
   (`noise_from: "group:G1/glit"`)? Adds triple→group edges; allowed by V1 if acyclic.
5. **Client**: new standalone program vs. extending `us_analysis_profile`; desktop only or also LIMS web page?
6. **Ownership/permissions**: pipeline visible to owner only, or to all users with access to the run?
7. **Cluster per stage** (e.g. MC on a larger cluster): add `cluster` as a stage parameter?
