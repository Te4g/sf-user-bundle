# Symfony Template Starter Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a thin `make starter` convenience command to `/Users/teag/Dev/symfony-template` that consumes the independent `te4g/starter` package without duplicating generated account code.

**Architecture:** The infrastructure template remains responsible only for Docker, FrankenPHP, databases, CI, deployment, and developer commands. Its Make target installs `te4g/starter` inside the existing PHP container, runs the same standalone generator, removes the package only after success, and leaves migration generation and execution explicit.

**Tech Stack:** GNU Make, Docker Compose, Composer, PHP 8.4+, Symfony 8.1, FrankenPHP, and the completed `te4g/starter` package.

## Global Constraints

- Execute this plan only after `2026-08-06-symfony-starter-generator.md` passes its completion gate.
- Modify `/Users/teag/Dev/symfony-template`; do not copy `User`, controllers, Twig, CSS, icons, or any other generated starter source into this repository.
- `make start` remains the infrastructure bootstrap; `make starter` requires an already-created Symfony application and running PHP container.
- Install `te4g/starter` as a Composer development dependency, run `vendor/bin/te4g-starter install --no-interaction`, and remove the package only after successful generation.
- On generator failure, Make stops immediately, returns nonzero, and leaves `te4g/starter` installed for diagnosis.
- The target never connects to the database, creates or applies a migration, or hides generator output.
- All changes follow TDD, run focused checks before each commit, and preserve unrelated working-tree changes.

---

## File and Responsibility Map

- `Makefile`: public `starter` target and overridable package requirement.
- `tests/starter-target.sh`: shell-level command ordering and failure-semantics test using `make --dry-run` plus a fake Docker executable.
- `scripts/smoke-starter.sh`: isolated real Docker integration using a local copy of the completed starter package.
- `README.md`: concise setup sequence and explicit migration steps.
- `docs/options.md`: `STARTER_REQUIREMENT` override for private/local package versions.

---

### Task 1: Add a Failure-Safe `make starter` Target

**Files:**
- Modify: `/Users/teag/Dev/symfony-template/Makefile`
- Create: `/Users/teag/Dev/symfony-template/tests/starter-target.sh`

**Interfaces:**
- Produces: Make variable `STARTER_REQUIREMENT ?= te4g/starter`.
- Produces: target `starter` that installs, generates, then removes in three ordered recipe lines.
- Preserves: generator package when the middle command exits nonzero.

- [ ] **Step 1: Write the failing target-order test**

```sh
#!/usr/bin/env sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
output=$(make -C "$root" --dry-run starter STARTER_REQUIREMENT='te4g/starter:dev-main')

require_line=$(printf '%s\n' "$output" | grep -n 'composer require' | cut -d: -f1)
install_line=$(printf '%s\n' "$output" | grep -n 'vendor/bin/te4g-starter install' | cut -d: -f1)
remove_line=$(printf '%s\n' "$output" | grep -n 'composer remove' | cut -d: -f1)

test "$require_line" -lt "$install_line"
test "$install_line" -lt "$remove_line"
! printf '%s\n' "$output" | grep -q 'make:migration'
! printf '%s\n' "$output" | grep -q 'doctrine:migrations:migrate'
```

- [ ] **Step 2: Run the shell test and verify the missing-target failure**

Run: `sh tests/starter-target.sh`

Expected: FAIL because `make starter` does not exist.

- [ ] **Step 3: Implement the thin Make target**

Add:

```make
STARTER_REQUIREMENT ?= te4g/starter

starter: ## Install the application-owned user and UI starter
	@$(COMPOSER) require --dev "$(STARTER_REQUIREMENT)" --no-interaction --no-progress
	@$(PHP_CONT) vendor/bin/te4g-starter install --no-interaction
	@$(COMPOSER) remove --dev te4g/starter --no-interaction --no-progress
```

Keep the commands on separate recipe lines. Make stops after the first nonzero line, so removal cannot run after a failed generator.

- [ ] **Step 4: Extend the test with a fake Docker failure**

Prepend a temporary fake `docker` executable to `PATH`. It records arguments, returns success for the Composer require call, returns exit code 19 for `vendor/bin/te4g-starter install`, and fails the test if it receives `composer remove`. Assert `make starter` exits nonzero and the record contains no remove call; GNU Make is allowed to translate the recipe's exit code to its own failure status.

- [ ] **Step 5: Run the focused Make test**

Run: `sh tests/starter-target.sh`

Expected: PASS for command order, no migration command, and preservation on generator failure.

- [ ] **Step 6: Commit the wrapper**

```bash
git add Makefile tests/starter-target.sh
git commit -m "feat: add Symfony starter setup target"
```

---

### Task 2: Document and Smoke-Test the Real Container Integration

**Files:**
- Create: `/Users/teag/Dev/symfony-template/scripts/smoke-starter.sh`
- Modify: `/Users/teag/Dev/symfony-template/README.md`
- Modify: `/Users/teag/Dev/symfony-template/docs/options.md`
- Modify: `/Users/teag/Dev/symfony-template/Makefile`

**Interfaces:**
- Produces: `scripts/smoke-starter.sh /absolute/path/to/te4g-starter`.
- Produces: `make starter-smoke STARTER_SOURCE=/absolute/path/to/te4g-starter`.
- Documents: `make start`, `make starter`, explicit migration review/execution.

- [ ] **Step 1: Write a failing documentation and smoke-policy check**

Add assertions to `tests/starter-target.sh`:

```sh
grep -q 'make starter' "$root/README.md"
grep -q 'php bin/console make:migration' "$root/README.md"
grep -q 'composer remove te4g/starter' "$root/scripts/smoke-starter.sh"
grep -q 'src/Entity/User.php' "$root/scripts/smoke-starter.sh"
```

- [ ] **Step 2: Run the test and verify missing documentation/script failures**

Run: `sh tests/starter-target.sh`

Expected: FAIL because the smoke script and starter documentation do not exist.

- [ ] **Step 3: Implement an isolated local-package smoke script**

The script must:

1. validate the provided starter directory contains `composer.json` with package name `te4g/starter`;
2. create a temporary directory with `mktemp -d`, then materialize the committed template into it with `git archive HEAD | tar -x`;
3. materialize the committed starter with `git -C <starter> archive HEAD | tar -x` into `<temp-template>/.local-packages/te4g-starter`;
4. run `docker compose up --build --detach --wait` in the temporary template;
5. configure Composer inside the PHP container with a path repository at `/app/.local-packages/te4g-starter`;
6. run `make starter STARTER_REQUIREMENT='te4g/starter:@dev'`;
7. assert `src/Entity/User.php`, `templates/layout/app.html.twig`, and `assets/styles/app.css` exist;
8. assert `composer show te4g/starter` fails and `rg` finds no runtime Te4g reference;
9. run container, YAML, Twig, Tailwind, and AssetMapper validation without a migration;
10. always run `docker compose down --volumes --remove-orphans` for the validated temporary directory.

Use an explicit Docker Compose project name derived from the temporary basename so the cleanup cannot affect another project.

- [ ] **Step 4: Document the user workflow and override**

README getting started becomes:

```console
make start
make starter
make sfc make:migration
make sfc doctrine:migrations:migrate
```

Explain that `make starter` is optional, generated code belongs to the application, and the package is removed automatically after success. In `docs/options.md`, document `STARTER_REQUIREMENT`, including `te4g/starter:@dev` for a configured local/path repository.

- [ ] **Step 5: Add and run the integration target**

```make
starter-smoke: ## Smoke test a local te4g/starter checkout
	@test -n "$(STARTER_SOURCE)" || { echo "STARTER_SOURCE is required" >&2; exit 1; }
	@./scripts/smoke-starter.sh "$(STARTER_SOURCE)"
```

Run: `sh tests/starter-target.sh`

Run: `make starter-smoke STARTER_SOURCE=/Users/teag/Dev/sf-user-bundle`

Expected: the target test and real container integration PASS; the generated app has no migration yet and no installed Te4g package.

- [ ] **Step 6: Run the template regression gate**

Run: `git diff --check`

Run: `docker compose config --quiet`

Run: `make --dry-run start`

Expected: all PASS and existing Docker/deployment commands are unchanged.

- [ ] **Step 7: Commit the documented integration**

```bash
git add Makefile README.md docs/options.md scripts/smoke-starter.sh tests/starter-target.sh
git commit -m "test: verify starter template integration"
```

---

## Completion Gate

From `/Users/teag/Dev/symfony-template`, run:

```bash
git status --short
sh tests/starter-target.sh
docker compose config --quiet
make starter-smoke STARTER_SOURCE=/Users/teag/Dev/sf-user-bundle
```

Expected: clean working tree; all checks PASS; the template continues to own infrastructure only, while the generated Symfony application owns the full user and UI starter.
