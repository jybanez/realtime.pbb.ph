# PBB Realtime Conference Capacity Comparison

## Scope

Compare conference scenarios on this machine:

- Intel Core i5-6500 @ 3.20 GHz
- 16 GB RAM

Compare two architectures:

1. `Pure signaling only`
   - current Realtime model
   - mesh WebRTC media
   - server handles signaling only

2. `With SFU`
   - same machine also forwards media

## Important Interpretation Rule

For `pure signaling only`, the numbers below are **server coordination estimates**, not guaranteed end-to-end conference quality counts.

For `mesh`, practical conference success is governed more by:

- endpoint CPU
- endpoint upload bandwidth
- Wi‑Fi quality
- browser/device performance

## Comparison Table

| Participants per conference | Pure signaling only: signaling-server coordination estimate | Pure signaling only: practical end-to-end mesh confidence | With SFU on same machine: cautious simultaneous conference estimate |
| --- | ---: | --- | ---: |
| 3 participants | 150 to 300 | Reasonable for real use | 12 to 20 |
| 4 participants | 120 to 250 | Caution zone | 8 to 14 |
| 5 participants | 100 to 200 | Pilot/testing only unless proven | 5 to 10 |

## Conservative Numbers For Presentation

If a principal needs simple numbers, use these conservative figures:

| Participants per conference | Pure signaling only: server-side signaling estimate | With SFU on same machine |
| --- | ---: | ---: |
| 3 participants | 150 conferences | 12 conferences |
| 4 participants | 120 conferences | 8 conferences |
| 5 participants | 100 conferences | 5 conferences |

These are acceptable as planning references only if you clearly label the first column as:

- `server-side signaling estimate`

and not:

- `guaranteed end-to-end conference capacity`

## Why Mesh And SFU Differ So Much

### Pure signaling only / mesh

The server handles:

- websocket signaling
- presence
- room events
- call coordination

The server does not forward media.

So:

- server load stays comparatively light
- endpoint load grows quickly

### With SFU

The server handles:

- signaling
- plus all conference media forwarding

So:

- server CPU and network throughput become the bottleneck
- simultaneous conference count drops sharply

## Defensible Statement

Use this wording:

> On the current small server, Realtime can likely coordinate many simultaneous conferences when used as signaling only. However, in mesh conferencing, whole-system quality is limited first by participant devices and network conditions, not by the signaling server. If we place an SFU on the same machine, then the server becomes the media bottleneck and the number of simultaneous conferences drops substantially.

## Recommendation By Scenario

### 3 participants

- mesh is operationally realistic
- signaling server is not the main concern

### 4 participants

- mesh is still possible
- reliability depends more heavily on devices and local network quality

### 5 participants

- mesh should be treated as pilot-grade unless field-tested
- SFU becomes the cleaner long-term architecture if this size becomes normal

## Next Step If Stronger Proof Is Needed

If these numbers need to be defended more aggressively, the next step is not more estimation. It is:

- a controlled load test
- on the actual machine
- using the actual expected resolution, frame rate, and call patterns

That will let you replace planning estimates with measured results.
