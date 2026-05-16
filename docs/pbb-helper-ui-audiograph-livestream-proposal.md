**Purpose**
Document a concrete Helper gap found while integrating `ui.audio.audiograph` into the PBB Realtime sandbox for live audio/video call testing.

**Problem**
`ui.audio.audiograph` is currently shaped around file/player-oriented `HTMLMediaElement` playback, not around live `MediaStream` analysis as a first-class use case.

That is workable for:
- audio file playback
- video file playback
- helper-owned player/viewer components

But it is weak for:
- WebRTC calls
- live remote audio streams
- local microphone monitoring
- session/call UIs that need the graph to respond to incoming/outgoing live media

**Observed In Realtime**
Repo:
- [resources/js/app.js](/c:/wamp64/www/pbb/realtime/resources/js/app.js)

Helper file:
- [ui.audio.audiograph.js](/c:/wamp64/www/pbb/realtime/public/vendor/helpers.pbb.ph/js/ui/ui.audio.audiograph.js)

Current Helper implementation facts:
- the component only accepts `attachAudio(nextAudioEl)`
- `attachAudio()` only accepts `HTMLMediaElement`
- `unlockAudioContext()` uses `audioContext.createMediaElementSource(audioEl)`
- demos are playback-oriented:
  - [demo.audio.html](/c:/wamp64/www/pbb/realtime/public/vendor/helpers.pbb.ph/demos/demo.audio.html)
  - [demo.media.viewer.html](/c:/wamp64/www/pbb/realtime/public/vendor/helpers.pbb.ph/demos/demo.media.viewer.html)

Realtime sandbox findings:
- remote audio can arrive successfully in a WebRTC call
- the audiograph can still fail to reflect live mic/remote audio reliably
- browser autoplay/Web Audio restrictions make unlock timing sensitive
- stream/session UIs need a simpler, stream-native contract than “bind a hidden media element and hope playback state aligns”

**Root Gap**
The current audiograph abstraction is media-element-first, not source-first.

That creates friction for live media because the app has to manage:
- hidden probe elements
- `srcObject` assignment
- `play()` timing
- first-user-gesture priming
- playback-state approximation
- reattachment during rerenders or renegotiation

That is too much app-owned ceremony for a component that should ideally handle live audio sources more directly.

**Proposal**
Extend `ui.audio.audiograph` so it can analyze live audio sources directly, not only `HTMLMediaElement`.

Recommended V1 additions:

1. `attachMediaStream(stream, options?)`
- accepts a `MediaStream`
- internally uses `audioContext.createMediaStreamSource(stream)`
- intended for:
  - local mic streams
  - remote WebRTC audio streams
  - live call/session UIs

2. `attachAudioNode(node, options?)`
- accepts an existing `AudioNode`
- intended for advanced apps that already manage Web Audio graphs

3. `resume()`
- explicit user-gesture-safe unlock/resume method
- should work regardless of whether the source is:
  - media element
  - media stream
  - audio node

4. source-aware playback state
- graph should not depend on file-player semantics such as `paused`/timeline
- for live streams, it should allow:
  - `isLive: true`
  - `isActive: boolean`
- and avoid assuming track progress/duration

**Suggested API Shape**
```js
const graph = createAudioGraph(host, {
  role: "local-audio",
  roleLabel: "Local audio",
  isPlaying: true,
}, {
  style: "tsunami",
  overlayHeader: false,
  showMute: false,
});

await graph.resume(); // called after user gesture
graph.attachMediaStream(localStream);
```

Advanced path:
```js
await graph.resume();
graph.attachAudioNode(mediaStreamSourceNode);
```

**Behavior Expectations**
For live streams:
- no hidden audio probe element should be required
- no timeline or duration should be required
- graph should react to live analyser data only
- graph should survive source swaps cleanly
  - renegotiation
  - camera on/off
  - remote stream replacement

**Why This Matters**
PBB apps are increasingly using Helper for terminal/session-style UIs, not only for static playback.

Examples:
- Realtime sandbox terminal
- future call-session UIs
- operator consoles with live remote participants

If `ui.audio.audiograph` remains file/player-centric, every downstream app will need to invent its own fragile stream-adapter layer.

If Helper adds native live-stream support, apps can use one clean contract for:
- local mic visualization
- remote call audio visualization
- session/media inspector surfaces

**Recommended Helper Acceptance Criteria**
1. `attachMediaStream(stream)` exists and works with live `MediaStream`
2. first user gesture can resume the graph without requiring app-specific probe hacks
3. graph reacts to live microphone input
4. graph reacts to live remote WebRTC audio
5. source replacement works without destroying/recreating the component
6. a demo exists for:
   - live microphone stream
   - remote-style synthetic or stream-backed audio

**Recommended Helper References To Add**
- `demos/demo.audio.audiograph.stream.html`
- `docs/ui-audio-audiograph-livestream-addendum.md`
- browser regression coverage for live stream attachment/resume

**Current Realtime Recommendation**
Until Helper adds stream-native support, Realtime can keep the current sandbox workaround, but it should be treated as an app-side compatibility layer, not as the ideal contract.
