"""
CenLearn Computer Vision — Face Tracking, Head Pose & Engagement Analytics Engine
===================================================================================
Real-time, privacy-first computer vision pipeline using normalized facial landmark geometry,
configurable Exponential Moving Average (EMA) temporal smoothing, state debouncing, 
session-level telemetry aggregation, baseline calibration, and ethical engagement reporting.

IMPORTANT ETHICAL & SCIENTIFIC PRINCIPLES:
1. This engine analyzes OBSERVABLE webcam-based head orientation and face presence signals.
2. It NEVER claims to directly measure a student's mental state, intelligence, or internal cognitive attention.
3. Facial signals support teacher decision-making as decision-support telemetry only, and NEVER
   determine academic risk or attendance penalties on their own.

Observable States:
- HEAD_CENTERED
- LOOKING_LEFT
- LOOKING_RIGHT
- LOOKING_UP
- LOOKING_DOWN
- LOOKING_SIDEWAYS (LOOKING_LEFT + LOOKING_RIGHT combined)
- NO_FACE
"""

import os
import time
import math
import numpy as np
from typing import Dict, List, Tuple, Optional, Any

# Scikit-Learn evaluation imports
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, confusion_matrix

# ── CONFIGURATION & THRESHOLDS ───────────────────────────────────────────────
DEFAULT_CONFIG = {
    "yaw_threshold": 0.18,          # Normalized left/right turn threshold
    "pitch_up_threshold": 0.20,     # Normalized upward tilt threshold
    "pitch_down_threshold": 0.20,   # Normalized downward tilt threshold
    "ema_alpha": 0.35,              # Exponential Moving Average coefficient (0.20 - 0.70)
    "min_state_duration_ms": 350,   # State debouncing threshold (ms)
    "sampling_fps": 10              # 10 FPS (100ms interval) for real-time tracking
}

STATE_LABELS = ["NO_FACE", "HEAD_CENTERED", "LOOKING_LEFT", "LOOKING_RIGHT", "LOOKING_UP", "LOOKING_DOWN"]


# ═════════════════════════════════════════════════════════════════════════════
# 1. FACIAL LANDMARK GEOMETRY & HEAD POSE ESTIMATION
# ═════════════════════════════════════════════════════════════════════════════
class HeadPoseEstimator:
    """Computes normalized Yaw, Pitch, and Roll from MediaPipe 3D face landmarks."""

    def __init__(self, config: Optional[Dict[str, Any]] = None):
        self.cfg = config or DEFAULT_CONFIG.copy()
        self.baseline_yaw = 0.0
        self.baseline_pitch = 0.0

    def calibrate_baseline(self, calibration_landmarks: List[Dict[str, float]]) -> Dict[str, float]:
        """Calibrates student's baseline seating position and laptop angle."""
        yaws = []
        pitches = []
        for lm in calibration_landmarks:
            raw_y, raw_p, _ = self._compute_raw_pose(lm)
            if raw_y is not None:
                yaws.append(raw_y)
                pitches.append(raw_p)

        if yaws:
            self.baseline_yaw = float(np.mean(yaws))
            self.baseline_pitch = float(np.mean(pitches))

        return {"baseline_yaw": self.baseline_yaw, "baseline_pitch": self.baseline_pitch}

    def _compute_raw_pose(self, lm: Dict[str, float]) -> Tuple[Optional[float], Optional[float], Optional[float]]:
        # Key landmark positions (MediaPipe Face Mesh indices or normalized dict keys)
        nose_x = lm.get("nose_x")
        nose_y = lm.get("nose_y")
        left_x = lm.get("left_cheek_x")
        right_x = lm.get("right_cheek_x")
        forehead_y = lm.get("forehead_y")
        chin_y = lm.get("chin_y")

        if None in (nose_x, nose_y, left_x, right_x, forehead_y, chin_y):
            return None, None, None

        # Horizontal Yaw Ratio
        face_width = abs(right_x - left_x)
        raw_yaw = 0.0
        if face_width > 0.001:
            raw_yaw = ((nose_x - left_x) / face_width) - 0.50

        # Vertical Pitch Ratio
        face_height = abs(chin_y - forehead_y)
        raw_pitch = 0.0
        if face_height > 0.001:
            raw_pitch = ((nose_y - forehead_y) / face_height) - 0.45

        # Roll estimation from relative cheek height delta
        left_y = lm.get("left_cheek_y", nose_y)
        right_y = lm.get("right_cheek_y", nose_y)
        raw_roll = (right_y - left_y) if face_width > 0.001 else 0.0

        return raw_yaw, raw_pitch, raw_roll

    def estimate_pose(self, lm: Optional[Dict[str, float]]) -> Tuple[Optional[float], Optional[float], Optional[float]]:
        if not lm:
            return None, None, None

        raw_y, raw_p, raw_r = self._compute_raw_pose(lm)
        if raw_y is None:
            return None, None, None

        # Apply baseline offset calibration
        calibrated_yaw = raw_y - self.baseline_yaw
        calibrated_pitch = raw_p - self.baseline_pitch
        return calibrated_yaw, calibrated_pitch, raw_r


# ═════════════════════════════════════════════════════════════════════════════
# 2. TEMPORAL SMOOTHING & STATE DEBOUNCER
# ═════════════════════════════════════════════════════════════════════════════
class TemporalStateSmoother:
    """Applies Exponential Moving Average (EMA) smoothing and state debouncing."""

    def __init__(self, alpha: float = 0.35, min_duration_ms: float = 350):
        self.alpha = alpha
        self.min_duration_ms = min_duration_ms
        self.ema_yaw = 0.0
        self.ema_pitch = 0.0
        self.current_state = "NO_FACE"
        self.pending_state = "NO_FACE"
        self.pending_since = 0.0

    def update(self, raw_yaw: Optional[float], raw_pitch: Optional[float], now_ms: float, config: Dict[str, Any]) -> str:
        if raw_yaw is None or raw_pitch is None:
            candidate_state = "NO_FACE"
            self.ema_yaw = 0.0
            self.ema_pitch = 0.0
        else:
            # Exponential Moving Average (EMA) update
            self.ema_yaw = (self.alpha * raw_yaw) + ((1.0 - self.alpha) * self.ema_yaw)
            self.ema_pitch = (self.alpha * raw_pitch) + ((1.0 - self.alpha) * self.ema_pitch)

            yaw_thresh = config.get("yaw_threshold", 0.18)
            pitch_up = config.get("pitch_up_threshold", 0.20)
            pitch_down = config.get("pitch_down_threshold", 0.20)

            if self.ema_yaw < -yaw_thresh:
                candidate_state = "LOOKING_LEFT"
            elif self.ema_yaw > yaw_thresh:
                candidate_state = "LOOKING_RIGHT"
            elif self.ema_pitch < -pitch_up:
                candidate_state = "LOOKING_UP"
            elif self.ema_pitch > pitch_down:
                candidate_state = "LOOKING_DOWN"
            else:
                candidate_state = "HEAD_CENTERED"

        # Debouncing filter
        if candidate_state != self.pending_state:
            self.pending_state = candidate_state
            self.pending_since = now_ms

        elapsed = now_ms - self.pending_since
        if elapsed >= self.min_duration_ms:
            self.current_state = self.pending_state

        return self.current_state


# ═════════════════════════════════════════════════════════════════════════════
# 3. SESSION-LEVEL TELEMETRY AGGREGATOR
# ═════════════════════════════════════════════════════════════════════════════
class SessionTelemetryAggregator:
    """Aggregates frame observations into time-window statistics and rates."""

    def __init__(self):
        self.reset()

    def reset(self):
        self.state_durations = {
            "HEAD_CENTERED": 0.0,
            "LOOKING_LEFT": 0.0,
            "LOOKING_RIGHT": 0.0,
            "LOOKING_UP": 0.0,
            "LOOKING_DOWN": 0.0,
            "NO_FACE": 0.0
        }
        self.state_change_count = 0
        self.previous_state = None
        self.total_observed_seconds = 0.0
        self.yaws = []
        self.pitches = []

    def record_frame(self, state: str, frame_duration_sec: float, yaw: Optional[float], pitch: Optional[float]):
        self.total_observed_seconds += frame_duration_sec
        self.state_durations[state] = self.state_durations.get(state, 0.0) + frame_duration_sec

        if self.previous_state is not None and state != self.previous_state:
            self.state_change_count += 1
        self.previous_state = state

        if yaw is not None:
            self.yaws.append(yaw)
            self.pitches.append(pitch)

    def get_summary(self) -> Dict[str, Any]:
        total = max(0.001, self.total_observed_seconds)
        
        centered_sec = self.state_durations.get("HEAD_CENTERED", 0.0)
        left_sec = self.state_durations.get("LOOKING_LEFT", 0.0)
        right_sec = self.state_durations.get("LOOKING_RIGHT", 0.0)
        up_sec = self.state_durations.get("LOOKING_UP", 0.0)
        down_sec = self.state_durations.get("LOOKING_DOWN", 0.0)
        no_face_sec = self.state_durations.get("NO_FACE", 0.0)

        sideways_sec = left_sec + right_sec
        face_present_sec = total - no_face_sec

        return {
            "total_observed_seconds": round(total, 2),
            "face_presence_rate": round(face_present_sec / total, 4),
            "head_centered_rate": round(centered_sec / total, 4),
            "sideways_rate": round(sideways_sec / total, 4),
            "looking_up_rate": round(up_sec / total, 4),
            "looking_down_rate": round(down_sec / total, 4),
            "no_face_rate": round(no_face_sec / total, 4),
            "state_change_frequency": round(self.state_change_count / (total / 60.0), 2) if total >= 60 else self.state_change_count,
            "yaw_variance": round(float(np.var(self.yaws)), 6) if self.yaws else 0.0,
            "pitch_variance": round(float(np.var(self.pitches)), 6) if self.pitches else 0.0,
            "observable_summary_text": f"{round((centered_sec / total) * 100)}% of observed session time had a head-centered orientation."
        }


# ═════════════════════════════════════════════════════════════════════════════
# 4. SYNTHETIC TELEMETRY GENERATOR & BENCHMARK SUITE
# ═════════════════════════════════════════════════════════════════════════════
def generate_synthetic_telemetry_dataset(n_samples: int = 500, seed: int = 42) -> Tuple[np.ndarray, np.ndarray]:
    """Generates synthetic head pose observations clearly labeled for pipeline testing."""
    np.random.seed(seed)
    yaws = []
    pitches = []
    labels = []

    for _ in range(n_samples):
        cat = np.random.choice([0, 1, 2, 3, 4, 5], p=[0.05, 0.70, 0.08, 0.08, 0.04, 0.05])
        if cat == 0:   # NO_FACE
            y, p = None, None
        elif cat == 1: # CENTERED
            y = np.random.normal(0.0, 0.05)
            p = np.random.normal(0.0, 0.05)
        elif cat == 2: # LEFT
            y = np.random.uniform(-0.40, -0.22)
            p = np.random.normal(0.0, 0.05)
        elif cat == 3: # RIGHT
            y = np.random.uniform(0.22, 0.40)
            p = np.random.normal(0.0, 0.05)
        elif cat == 4: # UP
            y = np.random.normal(0.0, 0.05)
            p = np.random.uniform(-0.35, -0.22)
        else:         # DOWN
            y = np.random.normal(0.0, 0.05)
            p = np.random.uniform(0.22, 0.35)

        yaws.append(y)
        pitches.append(p)
        labels.append(cat)

    return yaws, pitches, labels


def evaluate_classifier_benchmark(yaws: List[Optional[float]], pitches: List[Optional[float]], ground_truth: List[int]) -> Dict[str, Any]:
    estimator = HeadPoseEstimator()
    smoother = TemporalStateSmoother(alpha=0.35)
    
    predicted = []
    now_ms = 0.0
    
    for y, p in zip(yaws, pitches):
        now_ms += 100
        state = smoother.update(y, p, now_ms, DEFAULT_CONFIG)
        state_idx = STATE_LABELS.index(state) if state in STATE_LABELS else 0
        predicted.append(state_idx)

    acc = accuracy_score(ground_truth, predicted)
    prec = precision_score(ground_truth, predicted, average="weighted", zero_division=0)
    rec = recall_score(ground_truth, predicted, average="weighted", zero_division=0)
    f1 = f1_score(ground_truth, predicted, average="weighted", zero_division=0)
    cm = confusion_matrix(ground_truth, predicted, labels=list(range(6)))

    return {
        "accuracy": round(float(acc), 4),
        "precision": round(float(prec), 4),
        "recall": round(float(rec), 4),
        "f1_score": round(float(f1), 4),
        "confusion_matrix": cm.tolist()
    }


# ── TEST RUNNER ──────────────────────────────────────────────────────────────
if __name__ == "__main__":
    print("Executing CenLearn Computer Vision & Engagement Analytics Pipeline Test...")

    # 1. Generate Synthetic Dataset
    yaws, pitches, y_true = generate_synthetic_telemetry_dataset(n_samples=600)
    print(f"Generated Synthetic Telemetry: {len(y_true)} frames.")

    # 2. Evaluate Classifier Benchmark
    eval_res = evaluate_classifier_benchmark(yaws, pitches, y_true)
    print("\nBenchmark Evaluation Results:")
    print(f" - Accuracy:  {eval_res['accuracy']*100:.1f}%")
    print(f" - Precision: {eval_res['precision']:.4f}")
    print(f" - Recall:    {eval_res['recall']:.4f}")
    print(f" - F1-Score:  {eval_res['f1_score']:.4f}")

    # 3. Simulate 10-Minute Session Aggregation
    aggregator = SessionTelemetryAggregator()
    for y, p in zip(yaws, pitches):
        state = "HEAD_CENTERED" if y is not None and abs(y) < 0.18 else ("NO_FACE" if y is None else "LOOKING_SIDEWAYS")
        aggregator.record_frame(state, 0.1, y, p)

    summary = aggregator.get_summary()
    print("\n10-Minute Session Telemetry Summary:")
    print(f" • {summary['observable_summary_text']}")
    print(f" • Face Presence Rate:  {summary['face_presence_rate']*100:.1f}%")
    print(f" • Head Centered Rate:  {summary['head_centered_rate']*100:.1f}%")
    print(f" • Sideways Orientation: {summary['sideways_rate']*100:.1f}%")
    print(f" • State Change Frequency: {summary['state_change_frequency']} transitions")
