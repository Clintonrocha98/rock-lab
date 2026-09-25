# Rock Lab

Rock Lab is a local-first tool for practising system design: a person plugs their own Solution into a Scenario and reads a Report on how it behaves under load and failure. The tool judges external behaviour, never the code.

## Language

**Scenario**:
A declarative package that states one system design problem: its API contract, SLOs, Invariants and load plan.
_Avoid_: case, challenge, exercise, test

**Solution**:
The user's implementation of a Scenario's contract, described by a Docker Compose file.
_Avoid_: submission, project, app, stack

**Reference Solution**:
The Solution that ships with a Scenario as a worked example and as the tool's own test subject.
_Avoid_: example, sample

**Run**:
One execution of a Scenario's load plan against a Solution. Produces a Report.
_Avoid_: execution, test, attempt, job

**Phase**:
One ordered step of a Run, such as warmup, load, fault or persistence.
_Avoid_: stage, step

**SLO**:
A threshold a Scenario sets on a Run's measured behaviour, such as p99 latency or error rate.
_Avoid_: threshold, target, requirement

**Invariant**:
A correctness property a Scenario requires to hold during or after a Run, such as one short code never mapping to two URLs.
_Avoid_: assertion, check, rule

**Fault**:
A disruption Rock Lab injects into a Solution during a Run, such as killing a replica.
_Avoid_: failure, chaos, disruption

**Report**:
The outcome of a Run: SLOs met or missed, Invariants held or broken, effective resource limits.
_Avoid_: result, summary

**Comparable**:
A Run whose Solution stayed within the Scenario's resource limits, so its Report can be set against other Runs.
_Avoid_: valid, fair
