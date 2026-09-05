"""
CenLearn ML Model Trainer
=========================
Pulls student performance data from MySQL or generates realistic synthetic data,
engineers features, and trains a Random Forest Classifier to predict student academic risk.

Risk labels:
  0 = on_track   (rule score  0-30)
  1 = attention  (rule score 31-55)
  2 = at_risk    (rule score 56-75)
  3 = high_risk  (rule score 76-100)

Usage:
  python train.py

Saves trained model to: model/risk_model.pkl
"""

import os
import sys
import numpy as np
import mysql.connector
import joblib
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from sklearn.metrics import classification_report
from config import DB_CONFIG

MODEL_DIR  = os.path.join(os.path.dirname(__file__), "model")
MODEL_PATH = os.path.join(MODEL_DIR, "risk_model.pkl")

FEATURE_NAMES = [
    "quiz_avg_pct",    # 0.0–1.0  avg quiz score ratio
    "assign_avg_pct",  # 0.0–1.0  avg assignment grade ratio
    "missed_count",    # integer  number of missed assignments
    "attend_rate",     # 0.0–1.0  live session attendance ratio
    "late_rate",       # 0.0–1.0  ratio of late submissions
]

def score_to_label(score):
    if score <= 30: return 0
    if score <= 55: return 1
    if score <= 75: return 2
    return 3

def generate_synthetic_data(samples_per_class=300):
    """Generate realistic synthetic student dataset covering all 4 risk classes evenly."""
    np.random.seed(42)
    rows = []
    
    # Class 0: On Track
    for _ in range(samples_per_class):
        q = np.clip(np.random.uniform(0.70, 1.0), 0.0, 1.0)
        a = np.clip(np.random.uniform(0.70, 1.0), 0.0, 1.0)
        m = int(np.random.choice([0, 1]))
        att = np.clip(np.random.uniform(0.85, 1.0), 0.0, 1.0)
        lat = np.clip(np.random.uniform(0.0, 0.2), 0.0, 1.0)
        rows.append({"features": [q, a, m, att, lat], "label": 0})
        
    # Class 1: Needs Attention
    for _ in range(samples_per_class):
        q = np.clip(np.random.uniform(0.50, 0.70), 0.0, 1.0)
        a = np.clip(np.random.uniform(0.55, 0.75), 0.0, 1.0)
        m = int(np.random.choice([1, 2, 3]))
        att = np.clip(np.random.uniform(0.60, 0.85), 0.0, 1.0)
        lat = np.clip(np.random.uniform(0.1, 0.4), 0.0, 1.0)
        rows.append({"features": [q, a, m, att, lat], "label": 1})

    # Class 2: At Risk
    for _ in range(samples_per_class):
        q = np.clip(np.random.uniform(0.35, 0.55), 0.0, 1.0)
        a = np.clip(np.random.uniform(0.35, 0.55), 0.0, 1.0)
        m = int(np.random.choice([3, 4, 5]))
        att = np.clip(np.random.uniform(0.40, 0.65), 0.0, 1.0)
        lat = np.clip(np.random.uniform(0.3, 0.6), 0.0, 1.0)
        rows.append({"features": [q, a, m, att, lat], "label": 2})

    # Class 3: High Risk
    for _ in range(samples_per_class):
        q = np.clip(np.random.uniform(0.0, 0.35), 0.0, 1.0)
        a = np.clip(np.random.uniform(0.0, 0.35), 0.0, 1.0)
        m = int(np.random.choice([5, 6, 7, 8]))
        att = np.clip(np.random.uniform(0.0, 0.40), 0.0, 1.0)
        lat = np.clip(np.random.uniform(0.5, 1.0), 0.0, 1.0)
        rows.append({"features": [q, a, m, att, lat], "label": 3})

    np.random.shuffle(rows)
    X = np.array([r["features"] for r in rows], dtype=float)
    y = np.array([r["label"] for r in rows], dtype=int)
    return X, y

def fetch_training_data(conn):
    cursor = conn.cursor(dictionary=True)
    query = """
        SELECT 
            cm.user_code AS student_code, 
            cm.class_id,
            COALESCE(q.avg_pct, 0.5) AS quiz_avg,
            COALESCE(a.avg_pct, 0.5) AS assign_avg,
            COALESCE(m.missed, 0) AS missed,
            COALESCE(att.attend_rate, 0.75) AS attend_rate,
            COALESCE(lat.late_rate, 0.0) AS late_rate
        FROM class_members cm
        JOIN users u ON cm.user_code = u.user_code
        LEFT JOIN (
            SELECT qs.student_code, q.class_id, 
                   AVG(qs.score / NULLIF(qs.total_points, 0)) AS avg_pct
            FROM quiz_submissions qs
            JOIN quizzes q ON qs.quiz_id = q.id
            WHERE qs.total_points > 0
            GROUP BY qs.student_code, q.class_id
        ) q ON cm.user_code = q.student_code AND cm.class_id = q.class_id
        LEFT JOIN (
            SELECT s.student_code, a.class_id, 
                   AVG(s.grade / NULLIF(a.points, 0)) AS avg_pct
            FROM assignment_submissions s
            JOIN assignments a ON s.assignment_id = a.id
            WHERE s.grade IS NOT NULL AND a.points > 0
            GROUP BY s.student_code, a.class_id
        ) a ON cm.user_code = a.student_code AND cm.class_id = a.class_id
        LEFT JOIN (
            SELECT cm_inner.user_code, cm_inner.class_id, COUNT(ass.id) AS missed
            FROM class_members cm_inner
            JOIN assignments ass ON ass.class_id = cm_inner.class_id
            WHERE (ass.due_date IS NULL OR ass.due_date < NOW())
              AND NOT EXISTS (
                  SELECT 1 FROM assignment_submissions s
                  WHERE s.assignment_id = ass.id AND s.student_code = cm_inner.user_code
              )
            GROUP BY cm_inner.user_code, cm_inner.class_id
        ) m ON cm.user_code = m.user_code AND cm.class_id = m.class_id
        LEFT JOIN (
            SELECT cm_inner.user_code, cm_inner.class_id,
                   COUNT(DISTINCT la.session_id) / NULLIF(COUNT(DISTINCT ls.id), 0) AS attend_rate
            FROM class_members cm_inner
            JOIN live_sessions ls ON ls.class_id = cm_inner.class_id AND ls.status IN ('live', 'ended')
            LEFT JOIN live_attendance la ON la.session_id = ls.id AND la.student_code = cm_inner.user_code
            GROUP BY cm_inner.user_code, cm_inner.class_id
        ) att ON cm.user_code = att.user_code AND cm.class_id = att.class_id
        LEFT JOIN (
            SELECT s.student_code, a.class_id,
                   SUM(CASE WHEN a.due_date IS NOT NULL AND s.submitted_at > a.due_date THEN 1 ELSE 0 END) / COUNT(s.id) AS late_rate
            FROM assignment_submissions s
            JOIN assignments a ON s.assignment_id = a.id
            GROUP BY s.student_code, a.class_id
        ) lat ON cm.user_code = lat.student_code AND cm.class_id = lat.class_id
        WHERE u.user_group = 'STUDENT'
    """
    cursor.execute(query)
    pairs = cursor.fetchall()
    cursor.close()

    if not pairs:
        return None, None

    rows = []
    for row in pairs:
        quiz_avg    = float(row["quiz_avg"])
        assign_avg  = float(row["assign_avg"])
        missed      = int(row["missed"])
        attend_rate = float(row["attend_rate"])
        late_rate   = float(row["late_rate"])

        quiz_p   = 0 if quiz_avg   >= 0.60 else round((0.60 - quiz_avg)   / 0.60 * 30)
        assign_p = 0 if assign_avg >= 0.60 else round((0.60 - assign_avg) / 0.60 * 25)
        miss_p   = min(missed * 5, 20)
        attend_p = 0 if attend_rate >= 0.50 else round((0.50 - attend_rate) / 0.50 * 15)
        late_p   = 0 if late_rate   <= 0.50 else round((late_rate - 0.50)   / 0.50 * 10)
        rule_score = min(100, quiz_p + assign_p + miss_p + attend_p + late_p)

        rows.append({
            "features": [quiz_avg, assign_avg, missed, attend_rate, late_rate],
            "label":    score_to_label(rule_score),
        })

    X = np.array([r["features"] for r in rows], dtype=float)
    y = np.array([r["label"]    for r in rows], dtype=int)
    return X, y

def train():
    X, y = None, None
    try:
        print("Connecting to database...")
        conn = mysql.connector.connect(**DB_CONFIG)
        X, y = fetch_training_data(conn)
        conn.close()
    except Exception as err:
        print(f"Database fetch skipped or unavailable ({err}). Using synthetic training data.")

    if X is None or len(X) < 10 or len(set(y)) < 2:
        print("Generating realistic synthetic student training dataset...")
        X, y = generate_synthetic_data(samples_per_class=300)

    classes_found = dict(zip(*np.unique(y, return_counts=True)))
    print(f"Dataset: {len(X)} samples | Classes: {classes_found}")

    clf = RandomForestClassifier(n_estimators=200, random_state=42, class_weight="balanced")

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=42, stratify=y
    )
    clf.fit(X_train, y_train)

    y_pred = clf.predict(X_test)
    accuracy = np.mean(y_pred == y_test) * 100
    print(f"\nModel Accuracy: {accuracy:.2f}%")
    print("\nClassification Report:")
    print(classification_report(
        y_test, y_pred,
        labels=[0, 1, 2, 3],
        target_names=["on_track", "attention", "at_risk", "high_risk"],
        zero_division=0
    ))

    os.makedirs(MODEL_DIR, exist_ok=True)
    joblib.dump({"model": clf, "features": FEATURE_NAMES, "accuracy": accuracy}, MODEL_PATH)
    print(f"Model successfully saved -> {MODEL_PATH}")

if __name__ == "__main__":
    train()
