# Runtime identity acquisition proposal

Responds to 8002. Realtime Developer owns the eventual single serialized execution; Hotline reviews this source/plan, Alfred coordinates release. Nothing in this document authorizes a probe or sequence launch. No identity evidence has yet been acquired by running these artifacts.

First verify the final exact source revision, clean tracked diff and hashes of the source artifacts by read-only review. Use a separately isolated checkout of that exact revision. Do not use moving branch names as acceptance. The review handoff supplies the exact commit containing this plan, which pins all the checked-in scripts together.

Bootstrap trust must precede running the identity mechanism: operator/platform-approved installation provenance and expected executable hashes for `C:/Python312/python.exe` and `C:/Program Files/PowerShell/7/pwsh.exe`, Windows x64 host and compatible nested Jobs. A runtime reporting its own path/version/hash is identification, not proof of trust. Existing command resolution only found paths; it has not established provenance, actual architecture, versions or ABI. Do not run an untrusted target to decide whether to trust it. No approved reference hashes are currently supplied. Obtaining reference provenance remains an external prerequisite; this proposal does not assert it exists.

After source review and explicit release of the identity-only stage, proposed command from the pinned checkout:

```powershell
C:/Python312/python.exe tools/supervise-diagnostic-review.py --phase identity --powershell 'C:/Program Files/PowerShell/7/pwsh.exe' --evidence NEW_PRIVATE_IDENTITY_EVIDENCE
```

The independent outer Windows Job contains PowerShell before resume and its Python identity child. The identity PowerShell script reads its current version/path/process architecture, Windows description/architecture, .NET runtime description/core assembly path, SHA256 of PowerShell/Python/core assembly and Python isolated `-I -S` version/pointer width/executable. It invokes no compiler, PHPUnit, Composer, application, collector or network command. Its bounded structured output is retained by the existing 8192-byte raw drains. Parent stores fixed outer.json and raw stdout/stderr; path fields stay in private evidence, not timeline messages. Identity child ceiling10 seconds plus shared1-second cleanup; hashing/native/OS scheduling/final evidence writes can still prevent a hard real-time wall bound. No automatic retry or deadline extension.

Accept only outer/root exit0, complete nonfailed nontruncated streams, stopped drainers, verified root/tree cleanup and no cleanup failures/unresolved handles. Require exactly one valid JSON record, cap8192 bytes, compare executable/core hashes and runtime versions/architecture to the independently approved inventory; intended Python3.12 x64 and PowerShell7 x64/.NET compatibility must be explicitly checked. Pointer width64 alone does not identify CPU architecture. Missing/mismatched/untrusted identity, truncation, timeout, failed evidence or host degradation stops all later stages. Runtime probe results do not verify ctypes structure layouts, native ABI, nested Job containment or compiler behavior; those remain subsequent bounded validation scope.

Identity success does not automatically launch compile/driver. Review preserved evidence and have Alfred release the distinct sequence stage. Then use the same pinned source and approved binaries with `--phase sequence` and a different new evidence directory, as the consolidated offline plan specifies. Compilation30s/driver20s remain nested ceilings inside aggregate51s+1s cleanup. Any artifact/runtime change invalidates prior acceptance. Later clean own-lockfile installation and PHP remain separate gated stages.
