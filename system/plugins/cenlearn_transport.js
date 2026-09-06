/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║   CENLEARN TRANSPORT LAYER v2.0                                             ║
 * ║   MediaTransport Abstraction | P2P + SFU Ready | Production Grade           ║
 * ║                                                                              ║
 * ║   Architecture:                                                              ║
 * ║     MediaTransport (interface)                                               ║
 * ║       ├── P2PTransport  (PeerJS mesh — current runtime)                     ║
 * ║       └── SFUTransport  (plug-in when SFU server is deployed)               ║
 * ║                                                                              ║
 * ║   Room-size decision (configurable):                                        ║
 * ║     ≤ 8   participants → P2PTransport                                       ║
 * ║     9–12  participants → P2PTransport (aggressive optimisation)             ║
 * ║     13+   participants → SFU preferred (advisory if no SFU deployed)        ║
 * ║     20+   participants → SFU required                                       ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 1 — CENTRALISED CONFIGURATION
   ══════════════════════════════════════════════════════════════════════════════ */

var CENLEARN_CONFIG = {

  /* ── Quality Levels (5-tier, highest stable, not maximum) ── */
  QUALITY_LEVELS: {
    LEVEL_1_HD: {
      label: 'HD',
      width: 1920, height: 1080, fps: 30, maxBitrate: 3000000,
      scaleDown: 1, degradePref: 'maintain-framerate'
    },
    LEVEL_2_HIGH: {
      label: 'HIGH',
      width: 1280, height: 720, fps: 30, maxBitrate: 1800000,
      scaleDown: 1, degradePref: 'maintain-framerate'
    },
    LEVEL_3_MEDIUM: {
      label: 'MEDIUM',
      width: 854, height: 480, fps: 24, maxBitrate: 800000,
      scaleDown: 1, degradePref: 'balanced'
    },
    LEVEL_4_LOW: {
      label: 'LOW',
      width: 640, height: 360, fps: 15, maxBitrate: 400000,
      scaleDown: 2, degradePref: 'maintain-resolution'
    },
    LEVEL_5_AUDIO_ONLY: {
      label: 'AUDIO',
      width: 320, height: 180, fps: 8, maxBitrate: 80000,
      scaleDown: 4, degradePref: 'maintain-resolution'
    }
  },

  /* ── Screen Share (always high resolution for readability) ── */
  SCREEN_SHARE: {
    width: 1920, height: 1080, fps: 15, maxBitrate: 1500000,
    degradePref: 'maintain-resolution'
  },

  /* ── Bitrate allocation by peer-bucket (video, kbps) ── */
  BITRATE_TABLE: {
    few      : { video: 3000000, audio: 64000 },   // 1–2 peers
    some     : { video: 1800000, audio: 64000 },   // 3–6 peers
    many     : { video: 1000000, audio: 64000 },   // 7–14 peers
    classroom: { video: 600000,  audio: 48000 }    // 15–30+ peers
  },

  /* ── Quality Hysteresis (prevents quality flickering) ── */
  HYSTERESIS: {
    DEGRADATION_DELAY_MS: 5000,    // Must maintain poor signal 5s before downgrading
    RECOVERY_DELAY_MS:    15000,   // Must maintain good signal 15s before upgrading
    COOLDOWN_MS:          8000,    // Minimum time between any quality change
    MIN_RESIDENCE_MS:     3000     // Must stay at a level at least 3s before any change
  },

  /* ── Network Quality Thresholds (composite score) ── */
  NETWORK: {
    EXCELLENT : { rtt: 50,  loss: 0.005, jitter: 15  }, // → LEVEL_1_HD
    GOOD      : { rtt: 100, loss: 0.020, jitter: 30  }, // → LEVEL_2_HIGH
    FAIR      : { rtt: 200, loss: 0.050, jitter: 50  }, // → LEVEL_3_MEDIUM
    POOR      : { rtt: 350, loss: 0.100, jitter: 100 }, // → LEVEL_4_LOW
                                                          // > POOR  → LEVEL_5_AUDIO_ONLY
    // Weights for composite score (must sum to 1.0)
    RTT_WEIGHT  : 0.40,
    LOSS_WEIGHT : 0.40,
    JITTER_WEIGHT: 0.20
  },

  /* ── Room-size Transport Decision ── */
  ROOM_SIZE: {
    P2P_MAX:         8,    // ≤8 → pure P2P mesh
    P2P_OPTIMISED:   12,   // 9–12 → P2P with aggressive optimisation
    SFU_PREFERRED:   13,   // 13+ → SFU preferred
    SFU_REQUIRED:    20    // 20+ → SFU required advisory
  },

  /* ── Subscription Priority ── */
  PRIORITY: {
    HIGH   : { scaleDown: 1,  bitrateMultiplier: 1.0 },  // Teacher, active speaker, screen share
    MEDIUM : { scaleDown: 2,  bitrateMultiplier: 0.6 },  // Large/visible tiles
    LOW    : { scaleDown: 3,  bitrateMultiplier: 0.3 },  // Small visible tiles
    MINIMAL: { scaleDown: 4,  bitrateMultiplier: 0.1 }   // Off-screen participants
  },

  /* ── Active Speaker Detection ── */
  SPEAKER: {
    AMPLITUDE_THRESHOLD: 0.05,   // RMS amplitude to consider speaking
    MIN_SPEAKING_MS:     350,    // Must speak 350ms before promoting
    DEMOTION_COOLDOWN_MS:2000    // Must be silent 2s before demoting
  },

  /* ── MediaPipe AI Settings ── */
  AI: {
    TARGET_FPS:      8,      // Inference target (actual: setInterval 125ms)
    TARGET_FPS_LOW:  5,      // For devices with ≤4 CPU cores
    EMA_ALPHA:       0.35,   // Exponential Moving Average coefficient
    STATE_DEBOUNCE_MS: 350,  // Debounce before confirming state transition
    CORE_THRESHOLD:  4       // ≤4 cores → use low FPS mode
  },

  /* ── Reconnection Manager ── */
  RECONNECT: {
    MAX_RETRIES:       5,
    INITIAL_BACKOFF_MS:1000,
    MAX_BACKOFF_MS:    16000,
    ICE_RESTART_WAIT_MS: 800  // Wait before ICE restart attempt
  },

  /* ── ICE Config ── */
  ICE: {
    STUN: [
      'stun:stun.l.google.com:19302',
      'stun:stun1.l.google.com:19302',
      'stun:stun.cloudflare.com:3478',
      'stun:stun.services.mozilla.com:3478'
    ],
    // TURN credentials are fetched server-side (action=get_ice_config)
    // to avoid hardcoding credentials in client JS
    CANDIDATE_POOL: 4,
    BUNDLE_POLICY: 'max-bundle',
    RTCP_MUX: 'require'
  },

  /* ── Stats Polling ── */
  STATS: {
    INTERVAL_MS: 2000,  // getStats() polling interval for diagnostics HUD
    HISTORY_SIZE: 30    // Number of samples to keep for trend analysis
  }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 2 — MEDIA TRANSPORT INTERFACE
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * MediaTransport — Abstract base for P2P and SFU transports.
 * The UI layer (live_class.php) should only call methods on this interface,
 * making it possible to swap P2P ↔ SFU without touching the UI.
 */
function MediaTransport() {
  this.transportType = 'ABSTRACT';
  this.participants = {};
  this._onTrackCallbacks = [];
  this._onParticipantJoinCallbacks = [];
  this._onParticipantLeaveCallbacks = [];
  this._onErrorCallbacks = [];
}

MediaTransport.prototype.connect = function(options) {
  throw new Error('MediaTransport.connect() must be implemented');
};
MediaTransport.prototype.publish = function(stream, options) {
  throw new Error('MediaTransport.publish() must be implemented');
};
MediaTransport.prototype.subscribe = function(participantId, options) {
  throw new Error('MediaTransport.subscribe() must be implemented');
};
MediaTransport.prototype.unsubscribe = function(participantId) {
  throw new Error('MediaTransport.unsubscribe() must be implemented');
};
MediaTransport.prototype.disconnect = function() {
  throw new Error('MediaTransport.disconnect() must be implemented');
};
MediaTransport.prototype.onTrack = function(cb) {
  this._onTrackCallbacks.push(cb);
};
MediaTransport.prototype.onParticipantJoin = function(cb) {
  this._onParticipantJoinCallbacks.push(cb);
};
MediaTransport.prototype.onParticipantLeave = function(cb) {
  this._onParticipantLeaveCallbacks.push(cb);
};
MediaTransport.prototype.onError = function(cb) {
  this._onErrorCallbacks.push(cb);
};
MediaTransport.prototype._emit = function(type, data) {
  var cbs = this['_on' + type.charAt(0).toUpperCase() + type.slice(1) + 'Callbacks'] || [];
  cbs.forEach(function(cb) { try { cb(data); } catch(e) { console.warn('[Transport emit]', e); } });
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 3 — P2P TRANSPORT (wraps existing PeerJS mesh)
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * P2PTransport — Implements MediaTransport using PeerJS (existing runtime).
 * This wraps the existing peer/peers/dataChannels globals from live_class.php
 * and exposes a clean interface for future SFU migration.
 */
function P2PTransport() {
  MediaTransport.call(this);
  this.transportType = 'P2P';
  this._optimisedMode = false; // enabled at 9+ participants
}
P2PTransport.prototype = Object.create(MediaTransport.prototype);
P2PTransport.prototype.constructor = P2PTransport;

P2PTransport.prototype.connect = function(options) {
  // Delegates to existing joinCall / joinAsStudent in live_class.php
  this._options = options || {};
  console.log('[P2PTransport] connect(), delegates to PeerJS joinCall');
};

P2PTransport.prototype.publish = function(stream, options) {
  // In P2P mode, "publish" means the stream is available for outgoing calls
  // live_class.php handles this via myStream
  console.log('[P2PTransport] publish() — stream ready for P2P calls');
};

P2PTransport.prototype.subscribe = function(participantId, options) {
  // In P2P, subscription = answering/calling a peer
  // live_class.php handles this; this method records intent for priority
  var priority = (options && options.priority) || 'MEDIUM';
  this.participants[participantId] = { priority: priority, subscribed: true };
  console.log('[P2PTransport] subscribe()', participantId, 'priority:', priority);
};

P2PTransport.prototype.unsubscribe = function(participantId) {
  if (this.participants[participantId]) {
    this.participants[participantId].subscribed = false;
  }
};

P2PTransport.prototype.disconnect = function() {
  this.participants = {};
  // Delegates to existing stopCall() in live_class.php
  console.log('[P2PTransport] disconnect() — delegates to stopCall()');
};

P2PTransport.prototype.setOptimisedMode = function(enabled) {
  this._optimisedMode = enabled;
  if (enabled) {
    console.log('[P2PTransport] Optimised mode enabled (9-12 participants)');
  }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 4 — SFU TRANSPORT STUB (plug-in ready for mediasoup/LiveKit)
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * SFUTransport — Stub implementation for future SFU integration.
 *
 * To activate with mediasoup:
 *   1. Deploy mediasoup Node.js server
 *   2. Replace stub methods with mediasoup-client SDK calls
 *   3. Point TransportFactory to return SFUTransport at correct threshold
 *
 * To activate with LiveKit:
 *   1. Include LiveKit client SDK
 *   2. Replace stub methods with livekit-client Room calls
 *   3. Set SFU_URL in options
 */
function SFUTransport() {
  MediaTransport.call(this);
  this.transportType = 'SFU';
  this._room = null;
  this._url = null;
}
SFUTransport.prototype = Object.create(MediaTransport.prototype);
SFUTransport.prototype.constructor = SFUTransport;

SFUTransport.prototype.connect = function(options) {
  this._url = (options && options.sfuUrl) || null;
  if (!this._url) {
    console.warn('[SFUTransport] No SFU URL configured. Falling back to P2P advisory.');
    this._emit('error', { code: 'NO_SFU_URL', message: 'SFU server not configured.' });
    return;
  }
  // TODO: connect to mediasoup or LiveKit when deployed
  console.log('[SFUTransport] Would connect to SFU at:', this._url);
};

SFUTransport.prototype.publish = function(stream, options) {
  // TODO: send stream to SFU via sendTransport.produce()
  console.log('[SFUTransport] Would publish stream to SFU');
};

SFUTransport.prototype.subscribe = function(participantId, options) {
  // TODO: request SFU to forward participant stream via recvTransport.consume()
  var layer = (options && options.layer) || 'medium';
  console.log('[SFUTransport] Would subscribe to', participantId, 'layer:', layer);
};

SFUTransport.prototype.unsubscribe = function(participantId) {
  console.log('[SFUTransport] Would unsubscribe from', participantId);
};

SFUTransport.prototype.disconnect = function() {
  if (this._room) {
    // TODO: room.disconnect()
    this._room = null;
  }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 5 — TRANSPORT FACTORY
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * TransportFactory — selects the appropriate transport based on participant count
 * and whether an SFU is actually available.
 */
var TransportFactory = {
  _sfuAvailable: false,
  _sfuUrl: null,

  /** Call this during app init if you have an SFU server. */
  registerSFU: function(url) {
    this._sfuAvailable = !!url;
    this._sfuUrl = url || null;
    console.log('[TransportFactory] SFU registered:', url);
  },

  /**
   * Create the best transport for the given participant count.
   * @param {number} participantCount - total including self
   * @returns {MediaTransport}
   */
  create: function(participantCount) {
    var cfg = CENLEARN_CONFIG.ROOM_SIZE;

    if (participantCount <= cfg.P2P_MAX) {
      var t = new P2PTransport();
      console.log('[TransportFactory] → P2PTransport (' + participantCount + ' participants)');
      return t;
    }

    if (participantCount <= cfg.P2P_OPTIMISED) {
      var t2 = new P2PTransport();
      t2.setOptimisedMode(true);
      console.log('[TransportFactory] → P2PTransport (optimised, ' + participantCount + ' participants)');
      return t2;
    }

    if (participantCount >= cfg.SFU_REQUIRED && !this._sfuAvailable) {
      console.warn('[TransportFactory] 20+ participants but no SFU available — staying P2P with aggressive limits');
    }

    if (participantCount >= cfg.SFU_PREFERRED && this._sfuAvailable) {
      var t3 = new SFUTransport();
      t3.connect({ sfuUrl: this._sfuUrl });
      console.log('[TransportFactory] → SFUTransport (' + participantCount + ' participants)');
      return t3;
    }

    // SFU preferred but not available — stay P2P with optimised mode + advisory
    var t4 = new P2PTransport();
    t4.setOptimisedMode(true);
    return t4;
  },

  /** Return advisory message for the UI based on participant count. */
  getAdvisory: function(count) {
    var cfg = CENLEARN_CONFIG.ROOM_SIZE;
    if (count >= cfg.SFU_REQUIRED && !this._sfuAvailable) {
      return {
        level: 'warning',
        message: 'Large class detected (' + count + ' participants). For best performance with 20+ students, an SFU media server is recommended.'
      };
    }
    if (count >= cfg.SFU_PREFERRED && !this._sfuAvailable) {
      return {
        level: 'info',
        message: 'Class has ' + count + ' participants. Optimising quality settings automatically.'
      };
    }
    return null;
  }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 6 — SUBSCRIPTION PRIORITY MANAGER
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * SubscriptionPriorityManager — Tracks per-peer priority and applies
 * appropriate bitrate/resolution scaling via RTCRtpSender.setParameters().
 *
 * Priority order:
 *   1. SCREEN_SHARE  → HIGH (maintain-resolution)
 *   2. TEACHER       → HIGH
 *   3. ACTIVE_SPEAKER→ HIGH
 *   4. LARGE_VISIBLE → MEDIUM
 *   5. SMALL_VISIBLE → LOW
 *   6. OFF_SCREEN    → MINIMAL
 */
var SubscriptionPriorityManager = {
  _peers: {},           // { peerId: { priority, role, isOffScreen, isActiveSpeaker } }
  _teacherPeerId: null,
  _activeSpeakerId: null,
  _intersectionMap: {}, // peerId → isVisible

  /** Register a peer with its role. */
  register: function(peerId, role) {
    var isTeacher = (role === 'TEACHER');
    this._peers[peerId] = {
      peerId    : peerId,
      role      : role || 'STUDENT',
      priority  : isTeacher ? 'HIGH' : 'MEDIUM',
      isOffScreen: false,
      isActiveSpeaker: false
    };
    if (isTeacher) this._teacherPeerId = peerId;
    this._recompute(peerId);
  },

  /** Mark a peer as the current active speaker. */
  setActiveSpeaker: function(peerId) {
    // Demote previous active speaker
    if (this._activeSpeakerId && this._activeSpeakerId !== peerId) {
      var prev = this._peers[this._activeSpeakerId];
      if (prev) {
        prev.isActiveSpeaker = false;
        this._recompute(this._activeSpeakerId);
      }
    }
    this._activeSpeakerId = peerId;
    var p = this._peers[peerId];
    if (p) {
      p.isActiveSpeaker = true;
      this._recompute(peerId);
    }
  },

  /** Mark a peer as off-screen or on-screen. */
  setVisibility: function(peerId, isVisible) {
    var p = this._peers[peerId];
    if (!p) return;
    p.isOffScreen = !isVisible;
    this._recompute(peerId);
  },

  /** Remove a peer. */
  remove: function(peerId) {
    delete this._peers[peerId];
    if (this._activeSpeakerId === peerId) this._activeSpeakerId = null;
    if (this._teacherPeerId === peerId) this._teacherPeerId = null;
  },

  /** Calculate and return the priority label for a peer. */
  getPriority: function(peerId) {
    var p = this._peers[peerId];
    if (!p) return 'LOW';
    if (p.role === 'TEACHER') return 'HIGH';
    if (p.isActiveSpeaker)    return 'HIGH';
    if (p.isOffScreen)        return 'MINIMAL';
    return 'MEDIUM';
  },

  /** Get scaleResolutionDownBy for a peer, also considering screen share. */
  getScaleDown: function(peerId, isScreenShare) {
    if (isScreenShare) return 1; // Never scale down screen share
    var priority = this.getPriority(peerId);
    return CENLEARN_CONFIG.PRIORITY[priority].scaleDown;
  },

  /** Apply priority scaling to a PeerConnection's senders. */
  applyToPeerConnection: function(peerId, pc, screenStream, currentBitrate) {
    if (!pc) return;
    var scaleDown  = this.getScaleDown(peerId, false);
    var priorityCfg = CENLEARN_CONFIG.PRIORITY[this.getPriority(peerId)];
    var bitrateMultiplier = priorityCfg.bitrateMultiplier;

    try {
      var senders = pc.getSenders();
      for (var i = 0; i < senders.length; i++) {
        var sender = senders[i];
        if (!sender.track) continue;
        var params = sender.getParameters();
        if (!params.encodings || !params.encodings.length) params.encodings = [{}];
        var enc = params.encodings[0];

        if (sender.track.kind === 'video') {
          var isScreen = screenStream && screenStream.getVideoTracks &&
                         screenStream.getVideoTracks().includes(sender.track);
          if (isScreen) {
            enc.maxBitrate            = CENLEARN_CONFIG.SCREEN_SHARE.maxBitrate;
            enc.maxFramerate          = CENLEARN_CONFIG.SCREEN_SHARE.fps;
            enc.scaleResolutionDownBy = 1;
            enc.degradationPreference = CENLEARN_CONFIG.SCREEN_SHARE.degradePref;
          } else {
            enc.maxBitrate            = Math.floor((currentBitrate || 800000) * bitrateMultiplier);
            enc.scaleResolutionDownBy = scaleDown;
          }
        }
        sender.setParameters(params).catch(function(e) {
          console.warn('[SubscriptionPriority] setParameters failed:', e);
        });
      }
    } catch(e) {
      console.warn('[SubscriptionPriority] applyToPeerConnection error:', e);
    }
  },

  /** Internal: recompute and log priority for a peer. */
  _recompute: function(peerId) {
    var newPriority = this.getPriority(peerId);
    var p = this._peers[peerId];
    if (p) {
      p.priority = newPriority;
    }
  },

  /** Return a summary map for the diagnostics HUD. */
  getSummary: function() {
    return Object.keys(this._peers).map(function(pid) {
      var p = SubscriptionPriorityManager._peers[pid];
      return {
        peerId  : pid,
        role    : p.role,
        priority: p.priority,
        offScreen: p.isOffScreen,
        activeSpeaker: p.isActiveSpeaker
      };
    });
  }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 7 — NETWORK QUALITY CONTROLLER (5-tier with hysteresis)
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * NetworkQualityController — classifies network quality and applies
 * hysteresis to prevent rapid quality switching.
 *
 * Quality tiers and corresponding video levels:
 *   EXCELLENT → LEVEL_1_HD     (RTT<50ms,   loss<0.5%,  jitter<15ms)
 *   GOOD      → LEVEL_2_HIGH   (RTT<100ms,  loss<2%,    jitter<30ms)
 *   FAIR      → LEVEL_3_MEDIUM (RTT<200ms,  loss<5%,    jitter<50ms)
 *   POOR      → LEVEL_4_LOW    (RTT<350ms,  loss<10%,   jitter<100ms)
 *   CRITICAL  → LEVEL_5_AUDIO  (RTT≥350ms OR loss≥10%)
 */
var NetworkQualityController = {
  _currentTier    : 'GOOD',
  _currentLevel   : 2,         // index into level array
  _pendingTier    : null,
  _pendingStart   : 0,
  _lastChange     : 0,
  _onChangeCallback: null,
  _history        : [],

  TIER_TO_LEVEL: {
    EXCELLENT : 1,
    GOOD      : 2,
    FAIR      : 3,
    POOR      : 4,
    CRITICAL  : 5
  },

  LEVEL_TO_KEY: {
    1: 'LEVEL_1_HD',
    2: 'LEVEL_2_HIGH',
    3: 'LEVEL_3_MEDIUM',
    4: 'LEVEL_4_LOW',
    5: 'LEVEL_5_AUDIO_ONLY'
  },

  /** Register a callback that fires whenever the quality level actually changes. */
  onChange: function(cb) { this._onChangeCallback = cb; },

  /**
   * Feed a new measurement sample. Call this from the stats polling loop.
   * @param {number} rtt - round trip time in ms (or null)
   * @param {number} loss - packet loss ratio 0–1 (or null)
   * @param {number} jitter - jitter in ms (or null)
   */
  update: function(rtt, loss, jitter) {
    var tier = this._classify(rtt, loss, jitter);
    var now  = Date.now();
    var cfg  = CENLEARN_CONFIG.HYSTERESIS;

    // Maintain a rolling history for trend analysis
    this._history.push({ ts: now, tier: tier, rtt: rtt, loss: loss, jitter: jitter });
    if (this._history.length > CENLEARN_CONFIG.STATS.HISTORY_SIZE) {
      this._history.shift();
    }

    // Enforce cooldown — no change within cooldown period
    if (now - this._lastChange < cfg.COOLDOWN_MS) return;

    var newLevel = this.TIER_TO_LEVEL[tier];
    var curLevel = this._currentLevel;

    // If tier matches current — clear pending
    if (tier === this._currentTier) {
      this._pendingTier  = null;
      this._pendingStart = 0;
      return;
    }

    // If this is a NEW pending tier (different from current and from previous pending)
    if (this._pendingTier !== tier) {
      this._pendingTier  = tier;
      this._pendingStart = now;
      return;
    }

    // Pending tier has been sustained — check if delay threshold is met
    var elapsed = now - this._pendingStart;
    var delay   = (newLevel > curLevel)
      ? cfg.DEGRADATION_DELAY_MS   // going worse → degrade delay
      : cfg.RECOVERY_DELAY_MS;     // going better → recovery delay

    if (elapsed < delay) return;  // Not sustained long enough yet

    // Apply the quality change
    this._currentTier  = tier;
    this._currentLevel = newLevel;
    this._lastChange   = now;
    this._pendingTier  = null;
    this._pendingStart = 0;

    var levelKey = this.LEVEL_TO_KEY[newLevel];
    var levelCfg = CENLEARN_CONFIG.QUALITY_LEVELS[levelKey];

    console.log('[NetworkQuality] Quality change →', tier, '(' + (levelCfg ? levelCfg.label : '?') + ')');

    if (this._onChangeCallback) {
      try { this._onChangeCallback(tier, newLevel, levelKey, levelCfg); } catch(e) {}
    }
  },

  /** Classify current measurements into a tier name. */
  _classify: function(rtt, loss, jitter) {
    var n = CENLEARN_CONFIG.NETWORK;

    // Null measurements → cannot classify → stay at current
    if (rtt === null && loss === null) return this._currentTier;

    rtt    = rtt    || 0;
    loss   = loss   || 0;
    jitter = jitter || 0;

    // Compute composite score (lower = worse)
    // Normalise each metric to 0–1 scale relative to POOR threshold
    var rttNorm    = Math.min(rtt    / n.POOR.rtt,    1);
    var lossNorm   = Math.min(loss   / n.POOR.loss,   1);
    var jitterNorm = Math.min(jitter / n.POOR.jitter, 1);

    var badness = rttNorm    * n.RTT_WEIGHT
                + lossNorm   * n.LOSS_WEIGHT
                + jitterNorm * n.JITTER_WEIGHT;

    if (badness >= 1.0) return 'CRITICAL';

    // Also check absolute thresholds
    if (rtt > n.POOR.rtt || loss > n.POOR.loss) return 'POOR';
    if (rtt > n.FAIR.rtt || loss > n.FAIR.loss) return 'FAIR';
    if (rtt > n.GOOD.rtt || loss > n.GOOD.loss) return 'GOOD';
    if (rtt > n.EXCELLENT.rtt || loss > n.EXCELLENT.loss) return 'GOOD';
    return 'EXCELLENT';
  },

  /** Get current tier string. */
  getCurrentTier: function() { return this._currentTier; },

  /** Get current level number (1–5). */
  getCurrentLevel: function() { return this._currentLevel; },

  /** Get human-readable label for current level. */
  getCurrentLabel: function() {
    var key = this.LEVEL_TO_KEY[this._currentLevel];
    var cfg = CENLEARN_CONFIG.QUALITY_LEVELS[key];
    return cfg ? cfg.label : '?';
  }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 8 — RECONNECTION MANAGER (exponential backoff + lock)
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * ReconnectionManager — handles ICE restart with exponential backoff.
 * Prevents simultaneous reconnection attempts (reconnect lock).
 */
var ReconnectionManager = {
  _lock       : false,
  _retryCount : 0,
  _timer      : null,
  _onReconnect: null,
  _onGiveUp   : null,

  /** Register callbacks. */
  onReconnect: function(cb) { this._onReconnect = cb; },
  onGiveUp:    function(cb) { this._onGiveUp    = cb; },

  /** Reset state (call when connection is healthy again). */
  reset: function() {
    clearTimeout(this._timer);
    this._lock       = false;
    this._retryCount = 0;
    this._timer      = null;
  },

  /**
   * Attempt reconnection.
   * @param {Function} iceRestartFn - function that performs the actual ICE restart
   */
  attempt: function(iceRestartFn) {
    if (this._lock) {
      console.log('[ReconnectionManager] Already attempting reconnection, skipping duplicate.');
      return;
    }

    var cfg = CENLEARN_CONFIG.RECONNECT;
    if (this._retryCount >= cfg.MAX_RETRIES) {
      console.warn('[ReconnectionManager] Max retries reached. Giving up.');
      this._lock = false;
      if (this._onGiveUp) try { this._onGiveUp(); } catch(e) {}
      return;
    }

    this._lock = true;
    var backoff = Math.min(
      cfg.INITIAL_BACKOFF_MS * Math.pow(2, this._retryCount),
      cfg.MAX_BACKOFF_MS
    );
    this._retryCount++;

    console.log('[ReconnectionManager] Attempt', this._retryCount, '/', cfg.MAX_RETRIES,
                '— backoff:', backoff + 'ms');

    var self = this;
    this._timer = setTimeout(function() {
      if (typeof iceRestartFn === 'function') {
        try {
          iceRestartFn();
        } catch(e) {
          console.warn('[ReconnectionManager] ICE restart error:', e);
        }
      }
      // Release lock after ICE_RESTART_WAIT_MS to allow state to settle
      setTimeout(function() {
        self._lock = false;
        if (self._onReconnect) try { self._onReconnect(self._retryCount); } catch(e) {}
      }, cfg.ICE_RESTART_WAIT_MS);
    }, backoff);
  },

  /** Whether a reconnection attempt is in progress. */
  isAttempting: function() { return this._lock; },

  /** Get current retry count. */
  getRetryCount: function() { return this._retryCount; }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 9 — ACTIVE SPEAKER DETECTOR (hysteresis)
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * ActiveSpeakerDetector — detects active speaker with hysteresis to prevent
 * rapid speaker switching from noise spikes.
 *
 * Algorithm:
 *   - Feed RMS amplitude per peer every ~100ms
 *   - Peer must exceed threshold for MIN_SPEAKING_MS before promotion
 *   - Current speaker must be silent for DEMOTION_COOLDOWN_MS before demotion
 */
var ActiveSpeakerDetector = {
  _candidates    : {},   // { peerId: { rms, speakingStart } }
  _currentSpeaker: null,
  _silenceSince  : 0,
  _onSpeakerChange: null,

  /** Register callback. Fires with (newSpeakerId, prevSpeakerId). */
  onSpeakerChange: function(cb) { this._onSpeakerChange = cb; },

  /**
   * Feed an audio amplitude reading for a peer.
   * @param {string} peerId
   * @param {number} rms - 0–1 amplitude
   */
  feed: function(peerId, rms) {
    var cfg = CENLEARN_CONFIG.SPEAKER;
    var now = Date.now();
    var isSpeaking = rms > cfg.AMPLITUDE_THRESHOLD;

    if (!this._candidates[peerId]) {
      this._candidates[peerId] = { rms: 0, speakingStart: 0 };
    }
    this._candidates[peerId].rms = rms;

    if (isSpeaking) {
      if (!this._candidates[peerId].speakingStart) {
        this._candidates[peerId].speakingStart = now;
      }
      var duration = now - this._candidates[peerId].speakingStart;
      if (duration >= cfg.MIN_SPEAKING_MS && peerId !== this._currentSpeaker) {
        this._promote(peerId);
      }
      this._silenceSince = 0;
    } else {
      this._candidates[peerId].speakingStart = 0;
      if (peerId === this._currentSpeaker) {
        if (!this._silenceSince) this._silenceSince = now;
        var silenceDuration = now - this._silenceSince;
        if (silenceDuration >= cfg.DEMOTION_COOLDOWN_MS) {
          this._demote(peerId);
        }
      }
    }
  },

  _promote: function(peerId) {
    var prev = this._currentSpeaker;
    this._currentSpeaker = peerId;
    this._silenceSince   = 0;
    console.log('[ActiveSpeaker] Promoted:', peerId, '(was:', prev + ')');
    if (this._onSpeakerChange) {
      try { this._onSpeakerChange(peerId, prev); } catch(e) {}
    }
    SubscriptionPriorityManager.setActiveSpeaker(peerId);
  },

  _demote: function(peerId) {
    var prev = this._currentSpeaker;
    this._currentSpeaker = null;
    this._silenceSince   = 0;
    console.log('[ActiveSpeaker] Demoted:', prev);
    if (this._onSpeakerChange) {
      try { this._onSpeakerChange(null, prev); } catch(e) {}
    }
  },

  remove: function(peerId) {
    delete this._candidates[peerId];
    if (this._currentSpeaker === peerId) {
      this._currentSpeaker = null;
      this._silenceSince   = 0;
    }
  },

  getCurrentSpeaker: function() { return this._currentSpeaker; }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 10 — INTERSECTION OBSERVER MANAGER (off-screen tile quality)
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * TileVisibilityManager — uses IntersectionObserver to detect when participant
 * tiles scroll off-screen, triggering bitrate reduction for off-screen peers.
 */
var TileVisibilityManager = {
  _observer: null,
  _tileMap : {},   // tileId → { peerId, isVisible }

  /** Initialize the IntersectionObserver on the video grid container. */
  init: function(gridEl) {
    if (!window.IntersectionObserver) {
      console.warn('[TileVisibility] IntersectionObserver not supported — skipping');
      return;
    }
    if (this._observer) this._observer.disconnect();

    this._observer = new IntersectionObserver(
      this._onIntersection.bind(this),
      { root: gridEl || null, threshold: 0.1 }
    );
    console.log('[TileVisibility] Observer initialized on', gridEl ? 'grid' : 'viewport');
  },

  /** Observe a tile element. */
  observe: function(tileEl, peerId) {
    if (!this._observer || !tileEl) return;
    this._tileMap[tileEl.id] = { peerId: peerId, isVisible: true };
    this._observer.observe(tileEl);
  },

  /** Stop observing a tile element. */
  unobserve: function(tileEl) {
    if (!this._observer || !tileEl) return;
    delete this._tileMap[tileEl.id];
    this._observer.unobserve(tileEl);
  },

  /** Disconnect all observations (call on stopCall). */
  disconnect: function() {
    if (this._observer) {
      this._observer.disconnect();
      this._observer = null;
    }
    this._tileMap = {};
  },

  _onIntersection: function(entries) {
    var self = this;
    entries.forEach(function(entry) {
      var tileId = entry.target.id;
      var info   = self._tileMap[tileId];
      if (!info) return;
      var isVisible = entry.isIntersecting;
      info.isVisible = isVisible;
      SubscriptionPriorityManager.setVisibility(info.peerId, isVisible);
    });
  }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 11 — DEVICE CAPABILITY DETECTOR
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * DeviceCapability — detects device constraints and adjusts defaults.
 * Reads at startup; results are cached.
 */
var DeviceCapability = (function() {
  var _cores   = navigator.hardwareConcurrency || 4;
  var _isMobile= /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent);
  var _netType = (function() {
    var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    return c ? (c.effectiveType || '4g') : '4g';
  })();

  var _isLowEnd = _cores <= CENLEARN_CONFIG.AI.CORE_THRESHOLD || _isMobile;

  return {
    cores      : _cores,
    isMobile   : _isMobile,
    networkType: _netType,
    isLowEnd   : _isLowEnd,

    /** Return the appropriate AI processing interval in ms. */
    getAIInterval: function() {
      var targetFPS = _isLowEnd
        ? CENLEARN_CONFIG.AI.TARGET_FPS_LOW
        : CENLEARN_CONFIG.AI.TARGET_FPS;
      return Math.floor(1000 / targetFPS);
    },

    /** Return the initial video constraints for getUserMedia. */
    getInitialVideoConstraints: function() {
      if (_netType === 'slow-2g' || _netType === '2g') {
        return { width: { ideal: 640, max: 640 }, height: { ideal: 360, max: 360 }, frameRate: { ideal: 15, max: 15 } };
      }
      if (_netType === '3g' || _isMobile) {
        return { width: { ideal: 1280, max: 1280 }, height: { ideal: 720, max: 720 }, frameRate: { ideal: 24, max: 30 } };
      }
      return { width: { ideal: 1280, min: 640, max: 1920 }, height: { ideal: 720, min: 360, max: 1080 }, frameRate: { ideal: 30, max: 30 } };
    },

    /** Log device info to console for diagnostics. */
    report: function() {
      console.log('[DeviceCapability]',
        'Cores:', _cores,
        '| Mobile:', _isMobile,
        '| Network:', _netType,
        '| LowEnd:', _isLowEnd,
        '| AI FPS:', Math.floor(1000 / this.getAIInterval())
      );
    }
  };
})();

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 12 — ICE CONFIG HELPER
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * ICEConfigHelper — fetches ICE configuration from the server.
 * Falls back to STUN-only if the server endpoint is unavailable.
 * This avoids hardcoding TURN credentials in client-side JS.
 */
var ICEConfigHelper = {
  _cachedConfig: null,
  _fetchPromise : null,

  /**
   * Get ICE config — fetches from server once, caches result.
   * @param {string} handlerUrl - path to live_handler.php
   * @returns {Promise<{iceServers: Array}>}
   */
  getConfig: function(handlerUrl) {
    if (this._cachedConfig) return Promise.resolve(this._cachedConfig);
    if (this._fetchPromise) return this._fetchPromise;

    var self = this;
    var url  = (handlerUrl || 'live_handler.php') + '?action=get_ice_config';

    this._fetchPromise = fetch(url, {
      method: 'GET',
      cache : 'no-store',
      headers: { 'Accept': 'application/json' }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data && data.iceServers) {
        self._cachedConfig = { iceServers: data.iceServers };
      } else {
        self._cachedConfig = self._fallbackConfig();
      }
      self._fetchPromise = null;
      return self._cachedConfig;
    })
    .catch(function(err) {
      console.warn('[ICEConfig] Failed to fetch from server, using STUN-only fallback:', err);
      self._cachedConfig = self._fallbackConfig();
      self._fetchPromise = null;
      return self._cachedConfig;
    });

    return this._fetchPromise;
  },

  _fallbackConfig: function() {
    return {
      iceServers: CENLEARN_CONFIG.ICE.STUN.map(function(url) {
        return { urls: url };
      })
    };
  },

  /** Return PeerJS config object from ICE config. */
  toPeerConfig: function(iceConfig) {
    return {
      debug: 0,
      config: {
        iceServers       : iceConfig.iceServers,
        iceTransportPolicy: 'all',
        iceCandidatePoolSize: CENLEARN_CONFIG.ICE.CANDIDATE_POOL,
        bundlePolicy     : CENLEARN_CONFIG.ICE.BUNDLE_POLICY,
        rtcpMuxPolicy    : CENLEARN_CONFIG.ICE.RTCP_MUX
      }
    };
  }
};

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 13 — UTILITY: STATS HISTORY RING BUFFER
   ══════════════════════════════════════════════════════════════════════════════ */

/**
 * StatsRingBuffer — stores rolling WebRTC stats for trend calculation
 * and session-level analytics aggregation.
 */
function StatsRingBuffer(size) {
  this._size   = size || CENLEARN_CONFIG.STATS.HISTORY_SIZE;
  this._buffer = [];
}
StatsRingBuffer.prototype.push = function(sample) {
  this._buffer.push(sample);
  if (this._buffer.length > this._size) this._buffer.shift();
};
StatsRingBuffer.prototype.avg = function(key) {
  var vals = this._buffer.map(function(s) { return s[key]; }).filter(function(v) { return v != null; });
  if (!vals.length) return null;
  return vals.reduce(function(a, b) { return a + b; }, 0) / vals.length;
};
StatsRingBuffer.prototype.peak = function(key) {
  var vals = this._buffer.map(function(s) { return s[key]; }).filter(function(v) { return v != null; });
  if (!vals.length) return null;
  return Math.max.apply(null, vals);
};
StatsRingBuffer.prototype.count = function() { return this._buffer.length; };
StatsRingBuffer.prototype.clear = function() { this._buffer = []; };

/* ══════════════════════════════════════════════════════════════════════════════
   SECTION 14 — EXPORTS / INIT
   ══════════════════════════════════════════════════════════════════════════════ */

// Run device capability report on load
DeviceCapability.report();

// Make all classes and singletons available globally
window.CENLEARN_CONFIG          = CENLEARN_CONFIG;
window.MediaTransport           = MediaTransport;
window.P2PTransport             = P2PTransport;
window.SFUTransport             = SFUTransport;
window.TransportFactory         = TransportFactory;
window.SubscriptionPriorityManager = SubscriptionPriorityManager;
window.NetworkQualityController = NetworkQualityController;
window.ReconnectionManager      = ReconnectionManager;
window.ActiveSpeakerDetector    = ActiveSpeakerDetector;
window.TileVisibilityManager    = TileVisibilityManager;
window.DeviceCapability         = DeviceCapability;
window.ICEConfigHelper          = ICEConfigHelper;
window.StatsRingBuffer          = StatsRingBuffer;

console.log('[CenLearn Transport] v2.0 loaded — P2P/SFU abstraction layer ready.');
