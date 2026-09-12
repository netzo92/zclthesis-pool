# Zclassic live corroboration diagnostic

`0001-zclassic-live-corroboration-diagnostic.patch` applies only to the official
Zclassic `v2.1.2-beta6` source at commit
`14a83d510ffd109d3fa09bf74ebf8c28854a263f`. It adds a read-only
`live_corroboration` object to `getblockchaininfo`.

The release's `finalization_hold` describes the last finalization attempt. The
first peer announcing a block can leave that cached result at one corroborator,
even after other independent peers announce the same tip. The existing result is
retained. This patch independently evaluates current peer views on every RPC
call under `cs_main`, using the same `LiveNetworkCorroboratesTip` decision core.

It does not change `FindBlockToFinalize`, the finalized block pointer, block
validation, network behavior, configuration, consensus, or the cached hold. It
does not connect peers, search for proofs, or generate blocks.

The new object has these fields:

| Field | Meaning |
| --- | --- |
| `schemaVersion` | Integer `1` |
| `ready` | Strict boolean; fresh bootstrap and peer checks pass |
| `tipHash`, `tipHeight` | Exact active tip evaluated under the RPC's existing lock |
| `candidateHeight` | `tipHeight - requiredDepth`, or `-1` without a candidate |
| `requiredDepth` | Configured positive `maxreorgdepth`, or `-1` if invalid |
| `requiredPeers` | Existing configured minimum independent outbound groups, or `-1` if invalid |
| `reason` | Human-readable result; consumers must not parse it as an enum |

The imported-tip/bootstrap hold remains a prerequisite. The unchanged core
checks initial download, active ancestry, a best header received live this
session, sufficient descendant depth, independent outbound address groups, and
the higher-work competing-fork veto. Missing candidates or invalid configuration
fail closed. The diagnostic applies the peer check even if a node operator has
disabled peer-aware auto-finalization; it does not reinterpret that setting as
evidence of corroboration. Mainnet pool consumers additionally require at least
two groups and exact matching RPC tip identity.

This removes stale cached decisions, not real propagation delays: readiness can
still close until independent peers actually corroborate the current tip. It
also closes again when corroboration is lost. RPC work is bounded by the node's
connected peer set and existing ancestry lookup.

## Build and review

Apply only in an isolated source checkout:

```sh
git checkout 14a83d510ffd109d3fa09bf74ebf8c28854a263f
git apply --check /path/to/0001-zclassic-live-corroboration-diagnostic.patch
git apply /path/to/0001-zclassic-live-corroboration-diagnostic.patch
./zcutil/build.sh -j4
./src/test/test_bitcoin --run_test='bootstrap_snapshot_protocol_tests/*corroboration*' --log_level=test_suite
```

The new production-core test holds the same chain/candidate constant while the
peer view changes from one corroborator to two, back to lost/inbound/duplicate
peers, then to a higher-work fork. A separate test checks that the diagnostic
leaves the active tip, finalized pointer, and all cached hold fields unchanged.
The existing core tests continue covering IBD, live-header provenance, ancestry,
depth and fork rejection.

## Verified build and installation — 2026-09-12

`git apply --check` passed against the pinned clean checkout. Google Cloud Build
`62a2f6b2-92bb-43ba-94d4-dec93e42ed54` completed with status `SUCCESS`, using
Ubuntu 22.04 and the upstream commit above. The artifact bundle records the
source and patch in `node-build-provenance.json`, changed source hashes in
`PATCHED-SOURCES.sha256`, and output hashes in `SHA256SUMS`.

| Artifact | SHA256 |
| --- | --- |
| Diagnostic patch | `30f7fc2b1ff0b3debcce32f507ae7a77caa52b65bfe63d1b87f121ac31614d2b` |
| Built and installed `zclassicd` | `28898a96572900e820e04368c97ee8b0fc21b6cbbc62ed92adcd13d1e74c3d9d` |
| Built `zclassic-cli` | `1e0264fa4daf6245f08bf94572a5d02e1f6970043428db259d66632ba44638c9` |
| `corroboration-tests.log` | `5bf0cbb41ec56f3f15d0928c6232bba56e85b95744c7b33725c2e1ae58e794ca` |

The compiled Boost run selected exactly three cases and reported no errors:

- `finalization_peer_corroboration_gate`
- `live_corroboration_tracks_peers_without_new_block`
- `live_corroboration_does_not_mutate_finalization`

This evidence covers those three cases; the full node test suite was not run.
The daemon reports `v2.1.2-beta6-unk`, so the pinned source, patch, and artifact
hashes above identify this build more precisely than its version string.

The verified daemon was installed at `/opt/zclassic/zclassicd` on the pool VM.
The original release binary is preserved for rollback at
`/opt/zclassic/rollback/831b14cb794fb53a2e3d05a3143089799e725cf80da850168d271782101ac012/zclassicd`.
The node and pool consumers were deployed together; this patch remains a
read-only diagnostic change and does not alter finalization or consensus.
