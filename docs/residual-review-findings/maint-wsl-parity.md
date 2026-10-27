# Residual review findings: maint-wsl-parity

Source: multi-agent code review (ce-code-review, run `20261026-232325-1e10e2dd`) of the 2.1.1 WSL-parity branch against
`210066f7`. Every finding below was confirmed by an independent validator. Finding #1 was applied (`f7a289bc`). These
were not applied automatically because each rests on a single reviewer or changes behaviour that needs sign-off. No
tracker is configured for this repository, so they are recorded here and in the pull request.

## Residual Review Findings

- **P1** `app/Domain/Instance/Doctor/Checks/LocalCaPorts.php:78`: `fieldset config set lan-address` fails on Docker
  Engine in WSL with NAT (`lan_bind: all`) while the edge runs. `served()` requires the recorded and target bindings to be
  equal, including the advertised address, so the edge's own `0.0.0.0:80/443` look taken. Fix: compare where the running
  edge binds (bind address or bind-all) instead of the whole binding; add an EdgeBindingTest case with the edge holding
  `0.0.0.0:80`. (correctness)
- **P2** `distribution/shim.sh:340`: running the shim with `sudo` against a user-owned instance measures the root-written
  nonce as owner 0, so doctor reports a false mismatch and `fieldset repair-ownership` would hand the instance to root.
  Fix: when `id -u` is 0 and the saved `owner.host_uid` is not, use the saved owner instead of measuring;
  `OwnerChange::plan` refuses when the caller's UID differs from the state's host UID. (adversarial)
- **P2** `app/Domain/Instance/LocalCa/EdgeBinding.php:50`: the recorded `lan_bind` is never decided again after WSL's
  networking mode or the Docker setup changes; doctor shows only a port-probe failure and `config set lan-address <same>`
  does nothing. Fix: `WindowsNetworkCheck` compares the recorded binding with `EdgeBinding::decide()` for the current facts
  and fails with a hint naming `fieldset config set lan-address <advertised> --yes`; the lan-address and phone-https
  appliers treat a differing decided binding as a change. (adversarial)
- **P2** `distribution/shim.sh:175`: with phone HTTPS on the local CA, every command makes a powershell.exe address query
  (bounded at 60 s, typically 1–3 s), including `logs` and `version`, which never read the addresses. Fix: request the
  addresses scope only for commands that read them (consumers of `HostFacts::lanAddresses`, `LanAddress::best` and
  `LanAddress::isCurrent`: install, status, config set phone-https/lan-address, doctor, upgrade). (performance)
- **P2** `app/Domain/Instance/Ownership/OwnershipRepair.php:121`: the recovery path where the backup service fails to start
  after the chown (exit 4 `services_unhealthy`, state saved last, a second run succeeds) has no test. (testing)
- **P2** `app/Domain/Instance/Ownership/OwnerFiles.php:87`: the `secrets_exposed` branch (an env file not 0600 or
  unreadable after the chown) has no test. (testing)
- **P2** `app/Domain/Instance/Operations/BestEffortLock.php:33`: the failure paths (plan, work or release throwing; an
  unwritable state.json during first-use recording) have no test. (testing)

## Lower-confidence notes (not actionable on their own)

- The printed Hyper-V firewall rule has no profile or remote-address scope (security; parameter support unverified).
- The owner-measuring container and the browser opener's powershell.exe call have no timeout (reliability).
- The owner measurement gives up after one read; LEARNINGS 155 records single-read flakes over Desktop file sharing.
