# Docker socket access and the HOST_PWD path mirror

Research note for the tool composition decision. It states facts and trade-offs. It does not pick a solution.

Scope: the `worker` container drives the host Docker daemon through a bind-mounted socket. It runs `docker compose up` / `down -v` on a Solution that lives in a git-ignored folder of the repo. The plan mounts the repo at the same absolute path as on the host (`${HOST_PWD}:${HOST_PWD}`).

Evidence labels:

- **docs** — an official documentation page says it.
- **source** — code in a pinned commit does it.
- **maintainer** — a Docker maintainer said it in an issue comment. That is weaker than docs.
- **user report** — only a non-maintainer said it in an issue. Treat it as a lead, not a fact.
- **unverified** — no primary source confirms it. Section 5 gives the command that would confirm it.

Nothing here was reproduced on macOS or WSL2. One Linux data point was read on the machine that wrote this note (see 3.1).

Not covered: Colima, OrbStack, Rancher Desktop, Podman, rootless Docker, Docker Desktop for Linux, Windows without WSL.

---

## 1. Summary

- **Linux native dockerd** and **WSL2 with native dockerd** are the simple cases. The container sees the real socket: `root:docker`, mode `0660`. The `docker` GID is chosen per host. A non-root worker needs that numeric GID as a supplementary group. The path mirror works for bind mounts and for build contexts, because the client and the daemon share one filesystem.
- **Docker Desktop (macOS and WSL2)** special-cases `/var/run/docker.sock` as a bind-mount source. The container gets the socket from inside the Desktop VM, not the host socket. Its owner is `root:root`, and the host socket's GID has no meaning inside the container. So a GID read on the host (`stat` in `make`) is wrong there. A GID read inside the container at start is right, but it can be `0`.
- **Build contexts, `.env`, `env_file`, `extends`, `include` and build secrets** are read by the client (the Compose CLI in the worker). The mirror works for them wherever the worker can see the repo at `${HOST_PWD}`.
- **Bind mounts, file-based `configs` and file-based `secrets`** are resolved by the daemon. On Docker Desktop they depend on Desktop's path translation for requests that come from inside a container. That is **unverified** on macOS (likely works under `/Users`) and is the **biggest risk on WSL2 + Docker Desktop**: the in-VM socket does not know which distro the path belongs to.
- When the mirror is wrong, the failure is silent. The daemon creates an empty source directory as root and mounts it. Long syntax with `create_host_path: false` turns this into a hard error.
- What needs a fallback: socket access on Docker Desktop (GID 0 or no group write), and bind mounts from a Solution on WSL2 + Docker Desktop.

## 2. Matrix

| Environment               | Socket path on host                                                                   | Socket as seen inside a container that mounts `/var/run/docker.sock`                                                                                                               | What a non-root worker user needs                                                                                         | `${HOST_PWD}:${HOST_PWD}` for bind mounts                                                                                                                               | `${HOST_PWD}:${HOST_PWD}` for build contexts                                                                                        | Caveats                                                                                                                                                                       |
| ------------------------- | ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Linux, native dockerd** | `/var/run/docker.sock` (systemd unit listens on `/run/docker.sock`) [C4]              | Same socket. Owner `root`, group `docker`, mode `0660` [C1][C2][C3][D1]. GID set by `groupadd --system`, so it varies per host [C5][G3]                                            | Supplementary group with the host's **numeric** docker GID [I3][C7]                                                       | Works: one filesystem for client and daemon [D10]                                                                                                                       | Works: the client reads the context and sends it to BuildKit [D15][D16]                                                             | `docker` group is root-equivalent [D1]. Root in the worker writes root-owned files into the repo. Missing bind sources are auto-created as root [C6]. SELinux: **unverified** |
| **macOS, Docker Desktop** | `~/.docker/run/docker.sock`. `/var/run/docker.sock` is an optional symlink to it [D3] | The VM's socket, not the host's [D6][I4]. Owner `root:root` [I1][I2]. Mode seen as `srw-rw----` (2020, raw socket) [I1] and `srwxr-xr-x` (2023) [I2]. Current mode: **unverified** | Root user, or GID `0` if the socket is group-writable (user report after 4.21.1 [I2]). **Unverified** on current versions | Likely works if the repo is under a shared path (`/Users` is shared by default [D5]). Depends on the in-VM socket translating host paths: **unverified** [I1][I5]       | Works if the repo is under a shared path: the worker reads it through Desktop file sharing [D5]                                     | ECI blocks socket mounts by default [D17]. Do not mount `docker.raw.sock`: it seems to skip path translation (user report [I5]). Host GID is meaningless                      |
| **WSL2 + Docker Desktop** | `/var/run/docker.sock` inside the distro, after WSL integration is on [D7][D8]        | The VM's socket [D6]. Owner and mode: **unverified**                                                                                                                               | Same as macOS: **unverified**                                                                                             | **Unverified, high risk.** The worker's request reaches the VM socket, which does not know the distro. A user report says it resolves against the _default_ distro [I6] | Works if the worker's own mount works. The host CLI in the distro sends the mount, and Desktop translates distro paths [D9][I7][I8] | Keep the repo in the Linux filesystem, not `/mnt/c` [D9][M1]. Remove any native engine first [D8]                                                                             |
| **WSL2 + native dockerd** | `/var/run/docker.sock` inside the distro                                              | Same as Linux: `root:docker`, `0660`, GID per distro [C1][C3][C5]                                                                                                                  | Same as Linux                                                                                                             | Works: dockerd runs in the same distro and sees the same paths                                                                                                          | Works                                                                                                                               | Needs systemd or a manual daemon start [M3]. Conflicts with Docker Desktop [D8]. `/mnt/c` paths work but are slow and carry DrvFs permissions [M1][M2]                        |

## 3. Details

### 3.1 Socket permission

**Linux native dockerd (also WSL2 + native dockerd).**

- The daemon owns the socket as `root` and gives it to the `docker` group (docs [D1]). The source agrees: the default socket group is `docker` [C2], the listener chowns the socket to `root:<gid>` and chmods it `0660` [C1][C3]. The systemd socket unit sets `SocketMode=0660`, `SocketUser=root`, `SocketGroup=docker` [C4].
- The GID is not fixed. The Debian package runs `groupadd --system docker` [C5]. `groupadd --system` picks a free GID in `SYS_GID_MIN`–`SYS_GID_MAX` [G3], so the value depends on the groups that already exist. Data point from the machine that wrote this note: `stat -c '%U:%G %a %g' /var/run/docker.sock` printed `root:docker 660 973`.
- Linux checks the numeric GID, not the group name (maintainer [I3]). Compose `group_add` and `docker run --group-add` accept names or numbers [D11][D18]. The daemon resolves a **name** against the container's `/etc/group`, and fails if it is missing. A **number** is used as-is, even if absent from `/etc/group` (source [C7]). So `group_add: [docker]` does not carry the host's GID; a numeric GID does.
- Security: "The `docker` group grants root-level privileges to the user" (docs [D1]). "Only trusted users should be allowed to control your Docker daemon" (docs [D2]).

**Docker Desktop on macOS.**

- Host side: the socket lives at `~/.docker/run/docker.sock`. The `/var/run/docker.sock` symlink is an optional setting (docs [D3]). Whether that setting is on after a default install: **unverified**.
- Container side: Desktop special-cases `/var/run/docker.sock` as a bind-mount source. The container gets the socket from inside the VM, whatever the host path is (maintainer [I4]; docs [D6]: "Docker Desktop mounts the socket that lives in the Desktop VM, and not from the host").
- Owner and mode seen inside a container:
    - 2020, `docker.sock.raw` mounted: `srw-rw---- root root` (maintainer [I1]).
    - 2023, `docker.sock` mounted, Desktop 4.18: `srwxr-xr-x root root` (Docker engineer comment [I2]). The maintainer noted there is no `docker` group in the VM and the group is `root` [I2].
    - Desktop 4.21.1 shipped "a fix" for non-root access (maintainer [I2]). The release notes for 4.19–4.22 do not describe it. A user then reported that access works when the container user is added to the root group (GID 0) [I2]. The current mode is **unverified**.
- Security model: the VM is an extra boundary. Root in a container is not root on the Mac (docs [D3]; maintainer [I2]). But `/Users` is shared into the VM by default [D5], so API access still means read/write access to the user's home through a bind mount.
- `docker.sock.raw` (in the VM) and `docker.raw.sock` (on the host) skip Desktop's proxy. A maintainer warned the proxy exists "for a reason" [I1]. A user found that bind-mount path translation breaks through the raw socket [I5]. Enhanced Container Isolation, when an admin turns it on, blocks socket mounts by default [D17].

**WSL2 + Docker Desktop.**

- The daemon runs in Desktop's own `docker-desktop` distribution. Other distros talk to it only when WSL integration is on (docs [D8]). Inside an integrated distro, clients use `unix:///var/run/docker.sock` (docs [D7]).
- A container that mounts `/var/run/docker.sock` gets the VM socket (docs [D6] apply to Desktop in general). Its owner and mode on Windows: **unverified**. A for-win report shows the same `.raw` regression hit Windows and Mac at once, which suggests one shared VM design (user report, [I9]).
- Security model: root in a container does not grant Administrator access to Windows; the Linux VM is the boundary (docs [D4]).
- The distro-side socket's owner and group: **unverified**. It does not matter for the worker, which sees the VM socket.

**Consequence for a GID passed from the host.** Only on native dockerd is the host socket the same object the container sees. On Docker Desktop, the host `stat` reads a different socket (or a symlink, or nothing, if the optional symlink is off). This follows from [D3][D6][I4].

### 3.2 Who resolves which path

`docker compose` inside the worker is the **client**. It loads the Solution's Compose file, resolves relative paths, reads some files itself, and sends the rest to the daemon as absolute paths.

**Project directory.** The default project directory is the directory of the first Compose file, made absolute with `filepath.Abs` (source [C11]; docs [D14]). `filepath.Abs` relies on `os.Getwd`, which returns `$PWD` if `$PWD` names the current directory. With symlinks, it may return any of the paths (docs [G4]).

**Relative paths become absolute, on the client.** compose-go joins every relative path with the project directory [C8][C9]. This covers `build.context`, `build.additional_contexts`, `env_file`, `label_file`, `extends.file`, `include`, bind-mount `source` in `volumes`, `configs.*.file`, `secrets.*.file`, and `local` volumes with `o: bind`. `~` expands to the **client's** home directory (`os.UserHomeDir`) [C10]: inside the worker, that is the worker's `HOME`, not the host user's home. Compose docs: relative bind paths start with `.` or `..` and resolve from the Compose file's parent folder [D11]; relative build contexts resolve from the project directory [D12].

**Read by the client (in the worker):**

- The Compose files, `.env`, `env_file`, `label_file`, `extends`, `include` (compose-go loader [C8]).
- The build context and the Dockerfile. The build client reads `.dockerignore` and transfers the context to BuildKit (docs [D15]). BuildKit asks the client for local files and secrets (docs [D16]). Git and tarball contexts are fetched by the builder, not the client [D15].
- Build secrets with a `file` source (docs [D12], read through the build client [D16]).
- `configs` / `secrets` with `content` or `environment`: Compose copies them into the container through the API (source [C14]).

**Resolved by the daemon (on the host, or in the Desktop VM):**

- Bind mounts. "Bind mounts are created to the Docker daemon host, not the client" (docs [D10]). Compose sends the absolute source path [C13].
- File-based `configs` and `secrets`. Compose turns them into read-only bind mounts (source [C13]). The client only runs `os.Stat` on a secret file to print a warning [C13].

**A wrong path fails silently.** With `-v` or the Compose default (`create_host_path: true`), a missing source is created (docs [D10][D11]). The daemon creates it with `MkdirAllAndChown(..., 0o755, root)` (source [C6]). On Linux, that is a root-owned empty directory on the host, and the container sees an empty mount. With `--mount` or `create_host_path: false`, the daemon returns an error instead (docs [D10][D11]; Compose maps it to `CreateMountpoint` [C13]).

**Short syntax splits on `:`.** compose-go treats `:` as the field separator, with a special case for a Windows drive letter [C12]. A host path with `:` breaks the short syntax. Spaces are fine inside a quoted YAML string. Long syntax (`type: bind`, `source:`, `target:`) avoids both issues.

### 3.3 The path mirror, per platform

The mirror has two hops.

1. **Host CLI → worker.** `make start` runs Compose on the host. It mounts `${HOST_PWD}` into the worker at `${HOST_PWD}`. This is an ordinary bind mount from the host CLI.
2. **Worker CLI → Solution.** The worker runs `docker compose` on `${HOST_PWD}/<solution folder>`. The client reads files through hop 1. The daemon resolves bind sources as `${HOST_PWD}/...` on the host.

**Linux native dockerd.** Both hops see one filesystem. The mirror works for everything in 3.2. Caveat: a repo path that collides with a path in the worker image (for example `/var/www`) shadows that image content.

**macOS, Docker Desktop.**

- Hop 1 works if `${HOST_PWD}` is under a shared directory. `/Users`, `/Volumes`, `/private`, `/tmp` and `/var/folders` are shared by default. A path outside them gives `Mounts denied` (docs [D5]). VirtioFS is the default implementation; gRPC FUSE is the other option [D5].
- So the client-read files work at hop 2.
- Hop 2 bind mounts: the worker sends `/Users/<name>/.../data` to the in-VM socket. Inside the VM, shared host paths live under `/host_mnt/...` (user reports [I5]; also the error text in [I10]). The translation from `/Users/...` to `/host_mnt/Users/...` happens in Desktop's proxy, not in dockerd: the raw socket does not translate (user report [I5]). The socket that Desktop mounts for `/var/run/docker.sock` is the proxied one (maintainer [I1]). So the mirror is **likely** to work, but no primary source says that the in-VM proxy translates host paths for requests from a container: **unverified**.
- Historical docs (osxfs era, removed in 2020): paths not shared from macOS are "sourced from the Moby Linux VM", and a macOS path that is not shared and not in the VM fails rather than being created [D19]. Current behaviour: **unverified**.

**WSL2 + Docker Desktop.**

- Where the daemon lives: in the `docker-desktop` distribution, isolated from other distros [D8].
- Hop 1 works for a repo in the distro's Linux filesystem (for example `/home/<user>/rock-lab`). Docs recommend `docker run -v ~/my-project:/sources` from a Linux shell [D9]. Desktop tags such a mount as `wsl2DistroFile` (visible in `docker events`, user report [I7]). A mount from `/mnt/c/...` is a host (Windows) file; Desktop exposes it in the VM under `/run/desktop/mnt/host/c/...` (user report [I8]).
- Hop 2 is the open question. The worker's CLI talks to the VM socket, not to the distro's integration socket. Nothing tells the daemon which distro `/home/<user>/rock-lab` belongs to. One user report says bind mounts made through a socket mounted into a `docker run` container resolve to "the default WSL distro" [I6]. If that holds, the mirror works only when the repo lives in the default distro. The path `/run/desktop/mnt/host/wsl/...` from the question: no primary source found. All of this is **unverified**.
- `/mnt/c` is a poor home for the repo: slower bind mounts, and no inotify events in containers [D9][M1]. Files on DrvFs get their permissions from the Windows user unless the `metadata` mount option is on [M2].

**WSL2 + native dockerd.** dockerd and the CLI both run in the distro, so this is the Linux case. `/mnt/c/...` paths are visible to dockerd through DrvFs. They work, but are slow and have DrvFs ownership [M1][M2]. Microsoft enables systemd by default only for the current Ubuntu from `wsl --install`; other distros need `[boot] systemd=true` [M3]. Docker says to uninstall a native engine before installing Docker Desktop [D8].

### 3.4 `PWD`, `CURDIR` and spaces

- GNU make sets `CURDIR` to the current directory after `-C` processing. An environment variable `CURDIR` does not override it by default (docs [G1]).
- `$PWD` comes from the shell. It can be a logical path through a symlink. `$(realpath ...)` resolves symlinks; `$(abspath ...)` does not [G2]. Both host paths reach the same files, so either works for the mirror, as long as hop 1 and hop 2 use the same string.
- Typical values: Linux `/home/<user>/...`; macOS `/Users/<user>/...`; WSL2 `/home/<user>/...` or `/mnt/c/Users/<Windows user>/...`. The last one can contain spaces when the Windows user name has one. Microsoft's own example uses a quoted `"/mnt/c/Program Files"` [M1].
- Spaces: GNU make file name functions treat their argument as file names "separated by whitespace" [G2]. So `$(CURDIR)` must go straight into the environment of the recipe, quoted, never through a make function. In the Compose file, a quoted YAML value or the long syntax carries spaces fine [C12].
- Compose can refuse to start when the variable is missing: `${HOST_PWD:?message}` exits with an error if it is unset or empty [D13].
- macOS ships a BSD userland. Its `stat` takes `-f` for the format (BSD man page [G6]). GNU `stat` takes `-c` [G5]. That macOS ships BSD `stat` is **unverified** from an Apple source.

## 4. Options and trade-offs

### 4.1 Socket access

**(A) Entrypoint reads the socket GID at start, then drops to the worker user.**
The container starts as root. The entrypoint runs `stat -c %g /var/run/docker.sock` inside the container, creates or edits a group with that GID, adds the worker user, then execs the worker as that user (for example with `setpriv`, `su-exec` or `gosu`).

- Works: Linux native and WSL2 native, because the container sees the real GID. On Docker Desktop the GID is `0` (see 3.1). Adding the user to group 0 helps only if the socket is group-writable, which is **unverified**.
- Prior art: the devcontainers "docker-outside-of-docker" feature does this. It changes the `docker` group GID to the socket's GID. If the GID is `0`, it falls back to a `socat` relay (source [T2]).
- Security: the worker user gets full API access, which is root-equivalent on Linux [D1]. Membership in group 0 on Docker Desktop also grants access to other root-group files inside the container.
- Complexity: medium. An entrypoint script, a privilege-drop tool, and a special case for GID 0.

**(B) Compose `group_add` with a GID passed from the host by `make`.**
`make` runs `stat` on the host and exports, for example, `DOCKER_GID`. The Compose file sets `group_add: ["${DOCKER_GID:?}"]`. The number is used as-is [C7].

- Works: Linux native and WSL2 native.
- Wrong on Docker Desktop: the host socket is not the socket the container sees (3.1). On macOS, `/var/run/docker.sock` may not exist on the host at all [D3]. `stat` flags also differ (`-c` on GNU, `-f` on BSD) [G5][G6].
- Security: same as (A).
- Complexity: low on Linux. Needs platform detection in `make` to skip or override it on Docker Desktop.

**(C) Run the worker process as root.**

- Works on all four, for socket access alone.
- Security: on Linux, root in the container plus the socket is root on the host. On Docker Desktop, the VM limits root, but shared paths stay writable [D3][D5].
- Side effect on native dockerd: files that the worker creates in the repo (for example under the Solution folder) are root-owned on the host. On macOS, bind-mounted host directories "retain their original permissions" [D3]. WSL2 + Docker Desktop ownership: **unverified**.
- Complexity: lowest.

**(D) A socket proxy container, for example Tecnativa `docker-socket-proxy`.**
An HAProxy in front of the socket. It returns 403 for API sections that are not enabled with environment variables. `POST` is off by default, which makes the API read-only (source [T1]). The worker talks to it over TCP on an internal network, so the worker needs no socket permission.

- Works on all four for permission, because the proxy is the only thing that opens the socket. The proxy itself runs `--privileged` in its README, for SELinux/AppArmor contexts [T1].
- Security: it narrows the API surface. The worker still needs `POST` on containers, networks, volumes and images to run `compose up` / `down -v` and builds. Bind mounts stay allowed, so the root-equivalent risk remains. It moves the premise "only the worker mounts the socket" to "only the proxy mounts the socket".
- Complexity: one more service, plus an allowlist to maintain. Path translation on Docker Desktop still depends on which socket the proxy mounts.

**(E) A `socat` relay inside the worker.**
A root process in the worker connects to the real socket and listens on a second socket owned by the worker user. The devcontainers feature uses this when the socket GID is `0` (source [T2]).

- Works on all four, independent of the socket's GID and mode.
- Security: the same API power as (A); one extra root process in the container.
- Complexity: medium. A supervised extra process.

**(F) Root group (GID 0) as a supplementary group.**
A special case of (B) for Docker Desktop: `group_add: ["0"]`.

- Works on Docker Desktop only if the in-VM socket is `root:root` with group write. A user report says this works after 4.21.1 [I2]. **Unverified.**
- Does nothing on native dockerd, where the group is `docker`.

### 4.2 Paths

**(P1) Mirror path, with `HOST_PWD` passed from `make`.**
`make start` exports `HOST_PWD=$(CURDIR)` (or `$PWD`). Compose mounts `${HOST_PWD:?}` at the same path and sets it as the worker's working directory.

- Works: Linux, WSL2 native. macOS: client-read files yes, bind mounts **unverified** (likely). WSL2 + Docker Desktop: client-read files yes, bind mounts **unverified, high risk**.
- Needs `create_host_path: false` or a validation step, so that a wrong path fails loudly instead of mounting an empty directory [C6][D11].
- Needs quoting for spaces, and long syntax if the path may contain `:` [C12][G2].

**(P2) Mirror path, with the worker computing `HOST_PWD` itself.**
The worker inspects its own container through the API and reads the host source of its repo mount (`docker inspect` → `Mounts[].Source`).

- Removes one input from `make`. Needs the socket before the worker knows its paths.
- What `Mounts[].Source` shows on Docker Desktop (the host path, or a `/host_mnt/...` path): **unverified**.

**(P3) Fixed path in the worker, with path rewriting.**
Mount the repo at a fixed path (for example `/rock-lab`). Before `compose up`, rewrite bind sources from `/rock-lab/...` to `${HOST_PWD}/...`, for example from `docker compose config` output.

- Client-read files work at the fixed path. Only daemon-resolved paths get rewritten.
- Complexity: high. Every daemon-resolved field in 3.2 needs rewriting, including `configs` / `secrets` files and `local` volumes with `o: bind`. It does not solve WSL2 + Docker Desktop, where the host path itself may not resolve from the VM socket.

**(P4) No host paths in the Solution.**
The Scenario contract forbids bind mounts (and file-based `configs` / `secrets`) in a Solution. Data uses named volumes. Files the Solution needs go into the image at build time, or into a volume with `docker cp` / a seed container.

- Works on all four. Build contexts need no mirror, because the client reads and sends them [D15][D16]. So the worker can keep Solutions at any path it can read, and the repo mount path stops mattering for Runs.
- Named volumes fit `down -v` resets: `down --volumes` removes named volumes declared in the Compose file and anonymous volumes [D20].
- Cost: a restriction on the Solution contract. The web editor must validate it. Users lose live code editing through bind mounts during a Run.

**(P5) Validation instead of trust.**
Before a Run, the worker runs `docker compose config` and checks every bind source. It must start with `${HOST_PWD}/` and exist.

- Works with P1 or P2. It catches mistakes early, but it cannot prove that the daemon sees the same files on Docker Desktop.

## 5. Unverified, needs confirmation on a real machine

Each item gives the exact command. Run it on the named platform.

1. **Current owner and mode of the socket inside a container, Docker Desktop (macOS and WSL2).**
   `docker run --rm -v /var/run/docker.sock:/var/run/docker.sock alpine stat -c '%U:%G %a %g' /var/run/docker.sock`
2. **Non-root access with GID 0 on Docker Desktop.**
   `docker run --rm -u 1000:1000 --group-add 0 -v /var/run/docker.sock:/var/run/docker.sock docker:cli docker version`
3. **Non-root access with the socket's own GID (all platforms).**
   `docker run --rm -u 1000:1000 --group-add "$(docker run --rm -v /var/run/docker.sock:/var/run/docker.sock alpine stat -c %g /var/run/docker.sock)" -v /var/run/docker.sock:/var/run/docker.sock docker:cli docker version`
4. **Path mirror for a bind mount from inside a container, macOS and WSL2 + Docker Desktop.** Run from the repo root on the host; expect the file listing, not an empty directory:
   `mkdir -p probe && echo ok > probe/f && docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$PWD:$PWD" -w "$PWD" docker:cli docker run --rm --mount "type=bind,src=$PWD/probe,dst=/p" alpine cat /p/f`
5. **WSL2 + Docker Desktop, repo in a non-default distro.** Repeat item 4 in a distro that is not the default (`wsl.exe -l -v` shows the default), and in the default distro.
6. **WSL2 + Docker Desktop, repo under `/mnt/c`.** Repeat item 4 from `/mnt/c/Users/<user>/probe-dir`.
7. **What `Mounts[].Source` shows for the worker's own mount (option P2).**
   `docker run -d --name probe -v "$PWD:$PWD" alpine sleep 60 && docker inspect probe --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}'; docker rm -f probe`
8. **Whether `/var/run/docker.sock` exists on a default macOS install.**
   `ls -l /var/run/docker.sock ~/.docker/run/docker.sock`
9. **macOS ships BSD `stat`.**
   `stat -f '%Sg %g' ~/.docker/run/docker.sock` (works) and `stat -c %g ~/.docker/run/docker.sock` (fails)
10. **Owner of files a root container writes into a bind mount, WSL2 + Docker Desktop.**
    `docker run --rm -v "$PWD:/w" alpine touch /w/root-file && ls -ln root-file`
11. **SELinux hosts (Fedora, RHEL) block socket access despite the GID.**
    `getenforce && docker run --rm -u 1000 --group-add "$(stat -c %g /var/run/docker.sock)" -v /var/run/docker.sock:/var/run/docker.sock docker:cli docker version`
12. **Enhanced Container Isolation state on a Docker Desktop install.** Check Settings > General, or run item 1 and look for a denial error.

## 6. Sources

Docker documentation:

- [D1] Linux post-installation steps, "Manage Docker as a non-root user": https://docs.docker.com/engine/install/linux-postinstall/
- [D2] Docker Engine security, "Docker daemon attack surface": https://docs.docker.com/engine/security/#docker-daemon-attack-surface
- [D3] Understand permission requirements for Docker Desktop on Mac: https://docs.docker.com/desktop/setup/install/mac-permission-requirements/
- [D4] Understand permission requirements for Docker Desktop on Windows: https://docs.docker.com/desktop/setup/install/windows-permission-requirements/
- [D5] Docker Desktop settings, File sharing and Advanced: https://docs.docker.com/desktop/settings-and-maintenance/settings/
- [D6] Extensions SDK, "Use the Docker socket from the extension backend": https://docs.docker.com/extensions/extensions-sdk/guides/use-docker-socket-from-backend/
- [D7] Docker Desktop general FAQs, "How do I connect to the remote Docker Engine API?": https://docs.docker.com/desktop/troubleshoot-and-support/faqs/general/
- [D8] Docker Desktop WSL 2 backend: https://docs.docker.com/desktop/features/wsl/
- [D9] Docker Desktop WSL 2 best practices: https://docs.docker.com/desktop/features/wsl/best-practices/
- [D10] Bind mounts: https://docs.docker.com/engine/storage/bind-mounts/
- [D11] Compose file reference, services (`volumes`, `group_add`, `user`, `env_file`): https://docs.docker.com/reference/compose-file/services/
- [D12] Compose file reference, build: https://docs.docker.com/reference/compose-file/build/
- [D13] Compose file reference, interpolation: https://docs.docker.com/reference/compose-file/interpolation/
- [D14] Compose project name: https://docs.docker.com/compose/how-tos/project-name/
- [D15] Build context: https://docs.docker.com/build/concepts/context/
- [D16] Build overview (Buildx client, BuildKit server): https://docs.docker.com/build/concepts/overview/
- [D17] Enhanced Container Isolation, Docker socket exceptions: https://docs.docker.com/enterprise/security/hardened-desktop/enhanced-container-isolation/config/
- [D18] `docker container run` reference (`--group-add`, `--user`): https://docs.docker.com/reference/cli/docker/container/run/
- [D20] `docker compose down` reference: https://docs.docker.com/reference/cli/docker/compose/down/
- [D19] Historical: File system sharing (osxfs), removed from the docs in October 2020: https://github.com/docker/docs/blob/0bbe9c32fbfab356e47d97de56fa1f780f70e1ff/docker-for-mac/osxfs.md

Source code (pinned commits):

- [C1] moby, unix socket listener: https://github.com/moby/moby/blob/a9fa490ce1843945f96c578f4be025632ec19cda/daemon/listeners/listeners_linux.go#L35-L53
- [C2] moby, default socket group `docker`: https://github.com/moby/moby/blob/a9fa490ce1843945f96c578f4be025632ec19cda/daemon/listeners/group_unix.go#L12
- [C3] go-connections (vendored in moby), `NewUnixSocket` chown `0:gid`, chmod `0660`: https://github.com/moby/moby/blob/a9fa490ce1843945f96c578f4be025632ec19cda/vendor/github.com/docker/go-connections/sockets/unix_socket_unix.go#L69-L71
- [C4] moby, systemd `docker.socket` unit: https://github.com/moby/moby/blob/a9fa490ce1843945f96c578f4be025632ec19cda/contrib/init/systemd/docker.socket
- [C5] docker-ce-packaging, Debian postinst `groupadd --system docker`: https://github.com/docker/docker-ce-packaging/blob/a527854131ce458b50d6c294eca4879fc6e7d8db/deb/common/docker-ce.postinst
- [C6] moby, bind source auto-creation as root: https://github.com/moby/moby/blob/a9fa490ce1843945f96c578f4be025632ec19cda/daemon/volume/mounts/mounts.go#L239-L263
- [C7] moby, additional groups (numeric GIDs used as-is): https://github.com/moby/moby/blob/a9fa490ce1843945f96c578f4be025632ec19cda/vendor/github.com/moby/sys/user/user.go#L433-L496 and https://github.com/moby/moby/blob/a9fa490ce1843945f96c578f4be025632ec19cda/daemon/oci_linux.go#L182-L210
- [C8] compose-go, relative path resolvers: https://github.com/compose-spec/compose-go/blob/f18e211cbeaf7f411179c6f7b9f94057cb0db0d7/paths/resolve.go#L31-L62
- [C9] compose-go, `maybeUnixPath`: https://github.com/compose-spec/compose-go/blob/f18e211cbeaf7f411179c6f7b9f94057cb0db0d7/paths/unix.go#L26-L45
- [C10] compose-go, `ExpandUser` (`~` to client home): https://github.com/compose-spec/compose-go/blob/f18e211cbeaf7f411179c6f7b9f94057cb0db0d7/paths/home.go#L27-L37
- [C11] compose-go, `GetWorkingDir`: https://github.com/compose-spec/compose-go/blob/f18e211cbeaf7f411179c6f7b9f94057cb0db0d7/cli/options.go#L490-L510
- [C12] compose-go, short volume syntax parser: https://github.com/compose-spec/compose-go/blob/f18e211cbeaf7f411179c6f7b9f94057cb0db0d7/format/volume.go#L32-L81
- [C13] docker/compose, `configs` / `secrets` as bind mounts, `buildBindOption`: https://github.com/docker/compose/blob/bad7616c8f22e2f66bb54200df78dd6dd99b1d90/pkg/compose/create.go#L1204-L1300 and https://github.com/docker/compose/blob/bad7616c8f22e2f66bb54200df78dd6dd99b1d90/pkg/compose/create.go#L1399-L1407
- [C14] docker/compose, inline `configs` / `secrets` copied through the API: https://github.com/docker/compose/blob/bad7616c8f22e2f66bb54200df78dd6dd99b1d90/pkg/compose/secrets.go#L46-L77

Docker issue trackers (maintainer comments unless marked):

- [I1] docker/for-mac, proxied vs raw socket (maintainer thaJeztah): https://github.com/docker/for-mac/issues/4755#issuecomment-656092574 , https://github.com/docker/for-mac/issues/4755#issuecomment-656101962 , https://github.com/docker/for-mac/issues/4755#issuecomment-726351209
- [I2] docker/for-mac, non-root socket access regression: Docker engineer djs55 https://github.com/docker/for-mac/issues/6823#issuecomment-1531158122 ; maintainer thaJeztah https://github.com/docker/for-mac/issues/6823#issuecomment-1537495505 ; maintainer lorenrh (fix in 4.21.1) https://github.com/docker/for-mac/issues/6823#issuecomment-1618851919 ; user report (GID 0 works) https://github.com/docker/for-mac/issues/6823#issuecomment-1615198659
- [I3] docker/for-mac, numeric GID matters, not the group name (maintainer thaJeztah): https://github.com/docker/for-mac/issues/5072#issuecomment-729273417
- [I4] docker/for-mac, special handling of `/var/run/docker.sock` (maintainer thaJeztah): https://github.com/docker/for-mac/issues/6545#issuecomment-1295315796 , https://github.com/docker/for-mac/issues/6545#issuecomment-1295599122
- [I5] docker/for-mac, raw socket breaks bind-mount translation; `/host_mnt` in the VM (user report): https://github.com/docker/for-mac/issues/7647#issuecomment-2798778837 , https://github.com/docker/for-mac/issues/7647#issuecomment-2785576280
- [I6] docker/for-win, bind mounts through a mounted socket on WSL2 (user report): https://github.com/docker/for-win/issues/12654
- [I7] docker/for-win, `hostFile` vs `wsl2DistroFile` mount types (user report): https://github.com/docker/for-win/issues/14380
- [I8] docker/for-win, `/run/desktop/mnt/host/c/...` path form (user report): https://github.com/docker/for-win/issues/13432
- [I9] docker/for-win, `.raw` socket regression on Windows and Mac (user report): https://github.com/docker/for-win/issues/13451
- [I10] docker/for-mac, `/host_mnt/private/...` in a daemon error (user report): https://github.com/docker/for-mac/issues/6433

Microsoft WSL documentation:

- [M1] Working across file systems: https://learn.microsoft.com/en-us/windows/wsl/filesystems
- [M2] File permissions for WSL: https://learn.microsoft.com/en-us/windows/wsl/file-permissions
- [M3] Use systemd with WSL: https://learn.microsoft.com/en-us/windows/wsl/systemd

Other primary references:

- [G1] GNU make manual, `CURDIR`: https://www.gnu.org/software/make/manual/html_node/Recursion.html
- [G2] GNU make manual, file name functions: https://www.gnu.org/software/make/manual/html_node/File-Name-Functions.html
- [G3] shadow-utils `groupadd(8)`: https://man7.org/linux/man-pages/man8/groupadd.8.html
- [G4] Go `os.Getwd`: https://pkg.go.dev/os#Getwd
- [G5] GNU coreutils `stat`: https://www.gnu.org/software/coreutils/manual/html_node/stat-invocation.html
- [G6] FreeBSD `stat(1)`: https://man.freebsd.org/cgi/man.cgi?query=stat&sektion=1
- [T1] Tecnativa docker-socket-proxy (what it does only): https://github.com/Tecnativa/docker-socket-proxy
- [T2] devcontainers "docker-outside-of-docker" feature, README and entrypoint: https://github.com/devcontainers/features/tree/main/src/docker-outside-of-docker and https://github.com/devcontainers/features/blob/47406487f9b4965e4f9865f20b86805b1e2d5c0f/src/docker-outside-of-docker/install.sh#L503-L521
