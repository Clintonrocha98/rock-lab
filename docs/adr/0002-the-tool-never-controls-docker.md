# The tool never controls Docker; a Solution is reached at a URL

Rock Lab does not start, stop, reset or inspect the user's Solution. The user runs the Solution any way they like and gives Rock Lab its base URL. Rock Lab only sends HTTP requests to it. No container of the tool mounts the Docker socket. We chose this over a worker that drives the host Docker daemon, although that option supports Faults, persistence checks and resource limits. Driving the daemon from a container needs a different socket permission scheme per platform, and bind mounts from a Solution can fail silently on WSL2 with Docker Desktop. The cost did not pay for itself in the MVP.

## Considered options

- **Docker-out-of-Docker.** The worker mounts the host socket and runs the Solution's Compose file. It supports every planned Phase, but it needs a per-platform socket permission scheme and a path mirror.
- **Docker-in-Docker.** A privileged container runs its own daemon. It needs `--privileged` and nests storage under the latency we measure.
- **Solution at a URL.** Chosen. It works the same on every platform and gives the tool no root-equivalent access to the host.

## Consequences

- There is no Fault and no persistence Phase. Resilience needs a later design, for example a guided Fault where the user kills a replica on cue.
- There is no resource-limit check. A Scenario states its recommended limits as guidance only.
- Rock Lab does not reset a Solution before a Run. Every Invariant checks only data that the same Run created, so data left from an earlier Run cannot break it.
- k6 runs inside the worker image. The user installs nothing besides Docker and `make`.
- Inside a container, `localhost` is the container itself. Rock Lab rewrites a `localhost` or `127.0.0.1` host in the Solution URL to `host.docker.internal`.
